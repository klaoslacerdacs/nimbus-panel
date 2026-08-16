<?php

namespace App\Jobs;

use App\Models\OciStack;
use App\Services\OciBridgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OciStackDestroyJob implements ShouldQueue
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

        $destroy = $bridge->runStackJob($config, $stack->stack_ocid, 'DESTROY', confirmDestroy: true);

        if (! isset($destroy['job_id'])) {
            $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'Destroy job creation failed']);

            return;
        }

        $deadline = now()->addMinutes(30);

        while (now()->lt($deadline)) {
            sleep(30);

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

            if (! in_array($status, ['in_progress', 'accepted', 'waiting'])) {
                $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => "Destroy job ended with status: {$status}"]);

                return;
            }
        }

        $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'Destroy timed out after 30 minutes']);
    }
}
