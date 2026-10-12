<?php

namespace App\Jobs;

use App\Events\OciStackStatusChanged;
use App\Models\OciStack;
use App\Services\OciClient;
use App\Traits\RunsOciStackJobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OciStackDestroyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOciStackJobs, SerializesModels;

    // ponytail: blocks a queue worker for up to 30min — move to a self-rescheduling job if throughput matters
    public int $timeout = 1900;

    public int $tries = 1;

    public int $uniqueFor = 1900;

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

    public function handle(OciClient $oci): void
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

        $config = $stack->ociConnection->toApiConfig($stack->region);
        $stack->update(['status' => 'destroying', 'last_plan_summary' => 'Running Terraform destroy...']);
        OciStackStatusChanged::dispatch($stack->team_id);

        $destroy = $this->runTrackedStackJob($oci, $config, $stack, 'destroy');

        if ($destroy['ok']) {
            auditLog('job.oci_stack.destroyed', [
                'team_id' => $stack->team_id,
                'oci_stack_name' => $stack->name,
                'stack_ocid' => $stack->stack_ocid,
            ]);
            // Kept as history so users can tell "removed on OCI" from "removed only in the panel".
            $stack->update([
                'status' => 'destroyed',
                'last_plan_summary' => 'Terraform destroy succeeded at '.now()->toDateTimeString().' UTC. OCI resources removed.',
            ]);
        } else {
            $stack->update(['status' => 'destroy_failed', 'last_plan_summary' => "Terraform destroy failed.\n\n".($this->logTail($destroy['log']) ?? '')]);
        }

        OciStackStatusChanged::dispatch($stack->team_id);
    }

    /**
     * Horizon kills the job on timeout without running the end of handle(); never leave the stack "destroying".
     */
    public function failed(\Throwable $exception): void
    {
        $this->ociStack->update(['status' => 'destroy_failed', 'last_plan_summary' => 'Destroy stopped: '.$exception->getMessage()]);
        OciStackStatusChanged::dispatch($this->ociStack->team_id);
    }
}
