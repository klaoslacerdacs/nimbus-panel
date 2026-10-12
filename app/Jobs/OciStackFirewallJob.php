<?php

namespace App\Jobs;

use App\Events\OciStackStatusChanged;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Services\OciClient;
use App\Traits\RunsOciStackJobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies the stack's desired firewall rules through Terraform: update the stack to the current template
 * with the rules as variables, then plan → apply. Every run is versioned in oci_stack_jobs.
 */
class OciStackFirewallJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOciStackJobs, SerializesModels;

    // Plan (≤30m) + apply (≤30m).
    public int $timeout = 3900;

    public int $tries = 1;

    public int $uniqueFor = 3900;

    public function __construct(public OciStack $ociStack)
    {
        $this->onQueue('oci');
    }

    public function uniqueId(): string
    {
        return 'firewall-'.$this->ociStack->id;
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ["oci-stack:{$this->ociStack->id}", "team:{$this->ociStack->team_id}"];
    }

    public function handle(OciClient $oci): void
    {
        $stack = $this->ociStack->fresh(['ociConnection']);
        if (! $stack?->ociConnection || ! $stack->stack_ocid || $stack->status !== 'provisioned') {
            return;
        }

        $config = $stack->ociConnection->toApiConfig($stack->region);
        $rules = OciStack::normalizeFirewallRules($stack->firewall_rules ?? []);
        // Stacks created before rules were Terraform resources still carry rules added through the SDK.
        $adoptsLegacyRules = ! $stack->jobs()->where('operation', 'apply')->where('status', 'succeeded')->whereNotNull('firewall_rules')->exists();

        $updated = $oci->updateStack($config, $stack->stack_ocid, $this->getTemplateZipBase64(), $stack->terraformVariables());
        if (! empty($updated['error'])) {
            $this->finish($stack, 'Firewall update failed: could not update the stack ('.($updated['message'] ?? 'unknown error').').');

            return;
        }

        $plan = $this->runTrackedStackJob($oci, $config, $stack, 'plan', $rules);
        if (! $plan['ok']) {
            $this->finish($stack, "Firewall update failed at plan.\n\n".$this->logTail($plan['log']));

            return;
        }

        $apply = $this->runTrackedStackJob($oci, $config, $stack, 'apply', $rules);
        if (! $apply['ok']) {
            $this->finish($stack, "Firewall update failed at apply.\n\n".$this->logTail($apply['log']));

            return;
        }

        if ($adoptsLegacyRules && $stack->nsg_ocid) {
            $this->removeUnmanagedRules($oci, $config, $stack, $apply['job_id']);
        }

        auditLog('job.oci_stack.firewall_applied', ['team_id' => $stack->team_id, 'oci_stack_id' => $stack->id, 'rules' => count($rules)]);
        $this->finish($stack, 'Firewall rules applied by Terraform at '.now()->toDateTimeString().' UTC ('.count($rules).' rules).');
    }

    /**
     * Terraform now owns the NSG rules: drop the SDK-created duplicates once the managed ones exist (no closing window).
     *
     * @param  array<string, mixed>  $config
     */
    private function removeUnmanagedRules(OciClient $oci, array $config, OciStack $stack, string $applyJobId): void
    {
        $managedIds = $oci->getStackOutputs($config, $applyJobId)['ingress_rule_ids'] ?? null;
        if (! is_array($managedIds)) {
            return;
        }

        $legacyIds = collect($oci->listNsgRules($config, $stack->nsg_ocid, $stack->region))
            ->pluck('id')
            ->filter()
            ->diff($managedIds)
            ->values()
            ->all();

        if ($legacyIds !== []) {
            $oci->removeNsgRules($config, $stack->nsg_ocid, $legacyIds, $stack->region);
        }
    }

    private function finish(OciStack $stack, string $summary): void
    {
        $stack->update(['last_plan_summary' => $summary]);
        OciStackStatusChanged::dispatch($stack->team_id);
    }

    public function failed(\Throwable $exception): void
    {
        OciStackJob::failOpenJobs($this->ociStack, 'Firewall update stopped: '.$exception->getMessage());
        $this->ociStack->update(['last_plan_summary' => 'Firewall update stopped: '.$exception->getMessage()]);
        OciStackStatusChanged::dispatch($this->ociStack->team_id);
    }
}
