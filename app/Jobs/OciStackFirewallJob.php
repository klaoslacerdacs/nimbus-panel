<?php

namespace App\Jobs;

use App\Events\OciStackStatusChanged;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Services\OciClient;
use App\Traits\RunsOciStackJobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Applies the stack's desired firewall rules through Terraform: update the stack to the current template
 * with the rules as variables, then plan → apply. Every run is versioned in oci_stack_jobs.
 */
class OciStackFirewallJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
            Cache::forget($this->ociStack->pendingFirewallKey());

            return;
        }

        $config = $stack->ociConnection->toApiConfig($stack->region);
        // Stacks created before rules were Terraform resources still carry rules added through the SDK/console.
        $adoptsLegacyRules = ! $stack->jobs()->where('operation', 'apply')->where('status', 'succeeded')->whereNotNull('firewall_rules')->exists();
        if ($adoptsLegacyRules && $stack->nsg_ocid) {
            $this->adoptLiveRules($oci, $config, $stack);
        }
        // NSGs attached in the OCI console must survive the apply (Terraform sets the VNIC's full NSG list).
        if ($stack->attached_nsg_ids === null && ! $this->adoptAttachedNsgs($oci, $config, $stack)) {
            $this->finish($stack, 'Could not read the security groups attached to the VM, so the firewall was not applied (it could detach them). Try again.');

            return;
        }
        $attachedNsgIds = $stack->attached_nsg_ids;
        $rules = $stack->effectiveFirewallRules();

        $updated = $oci->updateStack($config, $stack->stack_ocid, $this->getTemplateZipBase64(), $stack->terraformVariables($rules));
        if (! empty($updated['error'])) {
            $this->finish($stack, 'Firewall update failed: could not update the stack ('.($updated['message'] ?? 'unknown error').').');

            return;
        }

        $plan = $this->runTrackedStackJob($oci, $config, $stack, 'plan', $rules);
        if (! $plan['ok']) {
            $this->finish($stack, "Firewall update failed at plan.\n\n".$this->logTail($plan['log']));

            return;
        }

        // A firewall change must never destroy or replace infrastructure (drift, template bug): stop before apply.
        if (self::planDestroysResources($plan['log'])) {
            $this->finish($stack, "Terraform plan wants to destroy or replace resources, so the apply was not run. Review the plan in Terraform Jobs.\n\n".$this->logTail($plan['log'], 30));

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

        // Rules, policies or attached NSGs changed while Terraform was applying: converge to the latest desired state.
        $latest = $stack->fresh();
        if (self::ruleKeys($latest->effectiveFirewallRules()) !== self::ruleKeys($rules) || ($latest->attached_nsg_ids ?? []) != ($attachedNsgIds ?? [])) {
            $stack->fresh()->queueFirewallChange();
        }
    }

    /**
     * True when a Terraform plan log announces destroying or replacing anything.
     */
    public static function planDestroysResources(?string $planLog): bool
    {
        if ($planLog === null) {
            return false;
        }

        return str_contains($planLog, 'must be replaced')
            || str_contains($planLog, 'will be destroyed')
            || (preg_match('/(\d+) to destroy/', $planLog, $m) === 1 && (int) $m[1] > 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return list<string>
     */
    private static function ruleKeys(array $rules): array
    {
        $keys = array_map(fn (array $rule): string => OciStack::firewallRuleKey($rule), OciStack::normalizeFirewallRules($rules));
        sort($keys);

        return $keys;
    }

    /**
     * Record the NSGs currently on the VM's VNIC (besides the stack's own) as attached.
     *
     * @param  array<string, mixed>  $config
     */
    private function adoptAttachedNsgs(OciClient $oci, array $config, OciStack $stack): bool
    {
        if (! $stack->oci_instance_id || ! $stack->compartment_ocid) {
            $stack->update(['attached_nsg_ids' => []]);

            return true;
        }

        $firewall = $oci->getInstanceFirewall($config, $stack->compartment_ocid, $stack->oci_instance_id, $stack->region);
        if (! empty($firewall['error'])) {
            return false;
        }

        $stack->update(['attached_nsg_ids' => array_values(array_diff($firewall['nsg_ids'] ?? [], [$stack->nsg_ocid]))]);

        return true;
    }

    /**
     * A live NSG rule (OciComputeService shape) in the desired-rule shape.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private static function desiredFromLive(array $rule): array
    {
        return [
            'protocol' => $rule['protocol_label'] ?? ['6' => 'TCP', '17' => 'UDP', '1' => 'ICMP', 'all' => 'ALL'][(string) ($rule['protocol'] ?? 'all')] ?? 'ALL',
            'port_min' => $rule['port_min'] ?? null,
            'port_max' => $rule['port_max'] ?? null,
            'source' => $rule['source'],
            'description' => $rule['description'] ?? '',
        ];
    }

    /**
     * Before Terraform takes over an NSG, record its current CIDR rules as desired so none is dropped (e.g. SSH).
     *
     * @param  array<string, mixed>  $config
     */
    private function adoptLiveRules(OciClient $oci, array $config, OciStack $stack): void
    {
        $live = $oci->listNsgRules($config, $stack->nsg_ocid, $stack->region);
        if (! empty($live['error'])) {
            return;
        }

        $adopted = collect($live)
            ->filter(fn (array $rule): bool => in_array($rule['source_type'] ?? 'CIDR_BLOCK', ['CIDR_BLOCK', null], true) && isset($rule['source']))
            ->map(fn (array $rule): array => self::desiredFromLive($rule))
            ->all();

        $stack->update(['firewall_rules' => OciStack::normalizeFirewallRules([...($stack->firewall_rules ?? []), ...$adopted])]);
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

        // Only delete old rules Terraform now provides itself; anything it cannot express stays untouched.
        $managedKeys = self::ruleKeys($stack->effectiveFirewallRules());
        $legacyIds = collect($oci->listNsgRules($config, $stack->nsg_ocid, $stack->region))
            ->reject(fn (array $rule): bool => in_array($rule['id'] ?? null, $managedIds, true))
            ->filter(fn (array $rule): bool => isset($rule['source']) && in_array(OciStack::firewallRuleKey(OciStack::normalizeFirewallRules([self::desiredFromLive($rule)])[0]), $managedKeys, true))
            ->pluck('id')
            ->values()
            ->all();

        if ($legacyIds !== []) {
            $oci->removeNsgRules($config, $stack->nsg_ocid, $legacyIds, $stack->region);
        }
    }

    private function finish(OciStack $stack, string $summary): void
    {
        Cache::forget($stack->pendingFirewallKey());
        $stack->update(['last_plan_summary' => $summary]);
        OciStackStatusChanged::dispatch($stack->team_id);
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget($this->ociStack->pendingFirewallKey());
        OciStackJob::failOpenJobs($this->ociStack, 'Firewall update stopped: '.$exception->getMessage());
        $this->ociStack->update(['last_plan_summary' => 'Firewall update stopped: '.$exception->getMessage()]);
        OciStackStatusChanged::dispatch($this->ociStack->team_id);
    }
}
