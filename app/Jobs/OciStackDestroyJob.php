<?php

namespace App\Jobs;

use App\Events\OciStackStatusChanged;
use App\Models\OciStack;
use App\Services\OciBridgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OciStackDestroyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ponytail: blocks queue worker for up to 30min — move to polling job if throughput matters
    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public OciStack $ociStack)
    {
        $this->onQueue('oci');
    }

    public function uniqueId(): string
    {
        return (string) $this->ociStack->id;
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ["oci-stack:{$this->ociStack->id}", "team:{$this->ociStack->team_id}"];
    }

    public function handle(OciBridgeClient $bridge): void
    {
        $stack = $this->ociStack->loadMissing('ociConnection');

        if (! $stack->ociConnection) {
            $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'OCI connection not found']);

            return;
        }

        if (! $stack->stack_ocid) {
            $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'No OCI stack OCID — nothing to destroy']);

            return;
        }

        $config = $stack->ociConnection->toBridgeConfig();
        $stack->update(['status' => 'destroying', 'last_plan_summary' => 'Running Terraform destroy...']);
        OciStackStatusChanged::dispatch($stack->team_id);

        $destroy = $bridge->runStackJob($config, $stack->stack_ocid, 'DESTROY', confirmDestroy: true);

        if (! isset($destroy['job_id'])) {
            $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'Destroy job creation failed']);

            return;
        }

        $deadline = now()->addMinutes(30);

        while (now()->lt($deadline)) {
            $result = $bridge->getStackJob($config, $destroy['job_id']);
            $status = strtolower($result['status'] ?? '');

            if ($status === 'succeeded') {
                auditLog('job.oci_stack.destroyed', [
                    'team_id' => $stack->team_id,
                    'oci_stack_name' => $stack->name,
                    'stack_ocid' => $stack->stack_ocid,
                ]);
                $stack->delete();

                return;
            }

            if ($status === '') {
                // Bridge unreachable (already retried 3x) — treat as transient, keep polling
                sleep(30);

                continue;
            }

            if (! in_array($status, ['in_progress', 'accepted', 'waiting'])) {
                $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => "Destroy job ended with status: {$status}"]);
                OciStackStatusChanged::dispatch($stack->team_id);

                return;
            }

            sleep(30);
        }

        $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'Destroy timed out after 30 minutes']);
        OciStackStatusChanged::dispatch($stack->team_id);
    }
}
