<?php

namespace App\Jobs;

use App\Models\OciStack;
use App\Services\OciBridgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OciStackProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ponytail: blocks queue worker for up to 30min — move to polling job if throughput matters
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public OciStack $ociStack) {}

    public function handle(OciBridgeClient $bridge): void
    {
        $stack = $this->ociStack->loadMissing('ociConnection');
        $config = $stack->ociConnection->toBridgeConfig();

        if (! $stack->stack_ocid) {
            $result = $bridge->createStack(
                $config,
                $stack->name,
                $stack->compartment_ocid,
                'ZIP_UPLOAD',
                ['zip_file_base64_encoded' => $this->getTemplateZipBase64()],
                $stack->tf_vars ?? []
            );

            if (! isset($result['stack_id'])) {
                $this->markFailed($stack, 'Stack creation failed');

                return;
            }

            $stack->update(['stack_ocid' => $result['stack_id'], 'status' => 'planning']);
        }

        $plan = $bridge->runStackJob($config, $stack->stack_ocid, 'PLAN');
        if (! isset($plan['job_id'])) {
            $this->markFailed($stack, 'Plan job creation failed');

            return;
        }

        if (! $this->pollUntilDone($bridge, $config, $plan['job_id'])) {
            $this->markFailed($stack, 'Plan failed or timed out');

            return;
        }

        $stack->update(['status' => 'applying']);

        $apply = $bridge->runStackJob($config, $stack->stack_ocid, 'APPLY');
        if (! isset($apply['job_id'])) {
            $this->markFailed($stack, 'Apply job creation failed');

            return;
        }

        if (! $this->pollUntilDone($bridge, $config, $apply['job_id'])) {
            $this->markFailed($stack, 'Apply failed or timed out');

            return;
        }

        $stack->update(['status' => 'provisioned']);
        // ponytail: Server IP + status updated when RM job outputs parsed in P4
    }

    private function pollUntilDone(OciBridgeClient $bridge, array $config, string $jobId): bool
    {
        $deadline = now()->addMinutes(30);

        while (now()->lt($deadline)) {
            sleep(30);

            $result = $bridge->getStackJob($config, $jobId);
            $status = strtolower($result['status'] ?? '');

            if ($status === 'succeeded') {
                return true;
            }

            if (! in_array($status, ['in_progress', 'accepted', 'waiting'])) {
                return false;
            }
        }

        return false;
    }

    private function getTemplateZipBase64(): string
    {
        return base64_encode(file_get_contents(base_path('terraform/templates/environment.zip')));
    }

    private function markFailed(OciStack $stack, string $reason): void
    {
        $stack->update(['status' => 'failed', 'last_plan_summary' => $reason]);
    }
}
