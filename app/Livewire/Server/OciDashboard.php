<?php

namespace App\Livewire\Server;

use App\Models\OciFirewallPolicy;
use App\Models\OciStack;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\OciClient;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OciDashboard extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public ?array $instanceStatus = null;

    public ?array $metrics = null;

    public array $nsgRules = [];

    /** @var array{vnic_id?: string|null, vcn_id?: string|null, compartment_id?: string|null, nsg_ids?: list<string>, nsgs?: list<array<string, mixed>>, security_lists?: list<array<string, mixed>>} */
    #[Locked]
    public array $firewall = [];

    public ?string $selectedNsgId = null;

    /** @var list<array{id: string, display_name: string|null}> NSGs of the VM's VCN (incl. created in the OCI console) */
    #[Locked]
    public array $availableNsgs = [];

    /** @var array<string, list<array<string, mixed>>> read-only rules of NSGs managed outside the panel, by NSG id */
    #[Locked]
    public array $externalNsgRules = [];

    public bool $confirmingStop = false;

    public bool $confirmingReboot = false;

    public array $newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];

    public ?string $stackStatus = null;

    public ?string $stackMessage = null;

    public bool $isProvisioning = false;

    public bool $statusLoadAttempted = false;

    public array $volumeAttachments = [];

    public array $loadBalancers = [];

    public array $autonomousDatabases = [];

    public array $routeTables = [];

    public array $buckets = [];

    public bool $confirmingDetach = false;

    #[Locked]
    public ?string $pendingDetachId = null;

    public string $attachVolumeOcid = '';

    public array $availableVolumes = [];

    public bool $showVolumeSelector = false;

    public bool $showCreateVolume = false;

    public string $newVolumeName = '';

    public int $newVolumeSize = 50;

    public function mount(string $server_uuid): void
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('view', $server);
        $this->server = $server;
        // Ensure settings record exists — older servers may lack it if created without observer
        if (! $server->settings) {
            ServerSetting::create(['server_id' => $server->id]);
            $server->load('settings');
        }
        $this->loadProvisioningStatus();
        // Imported servers have no stack; provisioned ones were already loaded by loadProvisioningStatus().
        if (! $this->isProvisioning) {
            $this->loadResources();
        }
    }

    private function loadResources(): void
    {
        if ($this->statusLoadAttempted) {
            return;
        }
        $this->statusLoadAttempted = true;
        $this->loadStatus();
        $this->loadMetrics();
        $this->loadNsgRules();
        $this->loadVolumeAttachments();
        $this->loadLoadBalancers();
        $this->loadAutonomousDatabases();
        $this->loadRouteTables();
        $this->loadBuckets();
    }

    public function loadProvisioningStatus(): void
    {
        $ociStack = $this->server->ociStack()->first();
        if (! $ociStack) {
            return;
        }

        $this->stackStatus = $ociStack->status;
        $this->stackMessage = $ociStack->last_plan_summary;
        $this->isProvisioning = $ociStack->isActive();

        if ($ociStack->status === 'provisioned') {
            $this->loadResources();
        }
    }

    #[\Override]
    public function getListeners(): array
    {
        return [
            "echo-private:team.{$this->server->team_id},OciStackStatusChanged" => 'loadProvisioningStatus',
        ];
    }

    public function loadStatus(): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            return;
        }

        $client = app(OciClient::class);
        $result = $client->getInstanceStatus($connection->toApiConfig(), $instanceId, $this->server->oci_region);
        $this->instanceStatus = isset($result['lifecycle_state']) ? $result : null;

        if (isset($result['lifecycle_state'])) {
            $this->server->update(['oci_instance_status' => $result['lifecycle_state']]);
        }
    }

    public function loadMetrics(): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            return;
        }

        if ($this->server->oci_metrics_source === 'sentinel') {
            try {
                $cpu = $this->server->getCpuMetrics(5);
                $memory = $this->server->getMemoryMetrics(5);
                $cpuVal = $cpu ? (float) (end($cpu)[1] ?? 0) : null;
                $memVal = $memory ? (float) (end($memory)[1] ?? 0) : null;

                if ($cpuVal !== null || $memVal !== null) {
                    $this->metrics = [
                        'cpu_percent' => $cpuVal !== null ? round($cpuVal, 2) : null,
                        'memory_percent' => $memVal !== null ? round($memVal, 2) : null,
                        'net_bytes_in' => null,
                        'net_bytes_out' => null,
                    ];

                    return;
                }
            } catch (\Throwable) {
                // Sentinel unavailable — fall through to OCI monitoring
            }
        }

        $client = app(OciClient::class);
        $result = $client->getInstanceMetrics(
            $connection->toApiConfig(),
            $instanceId,
            $this->server->oci_compartment_id ?? '',
            $this->server->oci_region
        );

        if (! array_key_exists('cpu_percent', $result)) {
            $this->metrics = null;

            return;
        }

        $this->metrics = ($result['cpu_percent'] !== null || ($result['memory_percent'] ?? null) !== null)
            ? $result
            : null;
    }

    /**
     * Discover what filters inbound traffic (NSGs on the VNIC + subnet security lists), then load the selected NSG rules.
     */
    public function loadNsgRules(): void
    {
        $connection = $this->server->ociConnection;
        if (! $connection || ! $this->server->oci_instance_id || ! $this->server->oci_compartment_id) {
            return;
        }

        $firewall = app(OciClient::class)->getInstanceFirewall($this->apiConfig(), $this->server->oci_compartment_id, $this->server->oci_instance_id, $this->server->oci_region);
        $this->firewall = empty($firewall['error']) ? $firewall : [];

        $nsgIds = $this->firewall['nsg_ids'] ?? [];
        if (! in_array($this->selectedNsgId, $nsgIds, true)) {
            $stackNsg = $this->server->ociStack()->value('nsg_ocid');
            $this->selectedNsgId = in_array($stackNsg, $nsgIds, true) ? $stackNsg : ($nsgIds[0] ?? null);
        }

        $this->nsgRules = [];
        if ($this->selectedNsgId) {
            $result = app(OciClient::class)->listNsgRules($this->apiConfig(), $this->selectedNsgId, $this->server->oci_region);
            $this->nsgRules = empty($result['error']) ? $result : [];
        }

        $this->availableNsgs = [];
        if (! empty($this->firewall['vcn_id'])) {
            $available = app(OciClient::class)->listVcnNsgs($this->apiConfig(), $this->firewall['compartment_id'], $this->firewall['vcn_id'], $this->server->oci_region);
            $this->availableNsgs = empty($available['error']) ? $available : [];
        }

        // Terraform-managed VMs edit only their own NSG; other attached NSGs are shown read-only.
        $this->externalNsgRules = [];
        if ($stack = $this->terraformStack()) {
            foreach (array_diff($nsgIds, [$stack->nsg_ocid]) as $nsgId) {
                $rules = app(OciClient::class)->listNsgRules($this->apiConfig(), $nsgId, $this->server->oci_region);
                $this->externalNsgRules[$nsgId] = empty($rules['error']) ? $rules : [];
            }
        }
    }

    /**
     * Attach a security group that already exists in the VM's VCN (e.g. created in the OCI console).
     */
    public function attachExistingNsg(string $nsgId): void
    {
        $this->changeAttachedNsgs($nsgId, attach: true);
    }

    public function detachExistingNsg(string $nsgId): void
    {
        $this->changeAttachedNsgs($nsgId, attach: false);
    }

    private function changeAttachedNsgs(string $nsgId, bool $attach): void
    {
        $this->authorize('update', $this->server);
        // Only NSGs of this VM's VCN, as listed server-side, can be attached; detaching only what is attached.
        $known = $attach ? array_column($this->availableNsgs, 'id') : ($this->firewall['nsg_ids'] ?? []);
        if (! in_array($nsgId, $known, true) || empty($this->firewall['vnic_id'])) {
            return;
        }

        $current = $this->firewall['nsg_ids'] ?? [];
        $next = $attach ? array_values(array_unique([...$current, $nsgId])) : array_values(array_diff($current, [$nsgId]));

        if ($stack = $this->terraformStack()) {
            if ($nsgId === $stack->nsg_ocid) {
                $this->dispatch('error', 'This is the VM\'s own security group managed by Terraform; it cannot be detached.');

                return;
            }
            $result = $stack->queueFirewallChange(null, $next);
            if ($result !== 'queued') {
                $this->dispatch('error', OciStack::firewallChangeError($result));

                return;
            }
            $this->dispatch('success', 'Security group change queued: Terraform runs plan → apply.');
        } else {
            $result = app(OciClient::class)->setVnicNsgs($this->apiConfig(), $this->firewall['vnic_id'], $next, $this->server->oci_region);
            if (! empty($result['error'])) {
                $this->dispatch('error', 'Could not update the security groups of this VM.', e($result['message'] ?? ''));

                return;
            }
            $this->dispatch('success', $attach ? 'Security group attached.' : 'Security group detached.');
            $this->loadNsgRules();
        }

        auditLog('ui.oci_nsg.'.($attach ? 'attached' : 'detached'), ['server_uuid' => $this->server->uuid, 'nsg_id' => $nsgId]);
    }

    public function updatedSelectedNsgId(): void
    {
        $this->loadNsgRules();
    }

    /**
     * @param  'ssh'|'http'|'https'  $preset
     */
    public function applyRulePreset(string $preset): void
    {
        $port = ['ssh' => 22, 'http' => 80, 'https' => 443][$preset] ?? null;
        if ($port) {
            $this->newRule = ['protocol' => 'TCP', 'port_min' => (string) $port, 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => strtoupper($preset)];
        }
    }

    /**
     * Imported VMs often have no NSG: create one in the VM's VCN and attach it to its VNIC.
     */
    public function createNsg(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->server->ociConnection || empty($this->firewall['vnic_id']) || empty($this->firewall['vcn_id'])) {
            $this->dispatch('error', 'Could not find the network interface of this instance.');

            return;
        }

        $result = app(OciClient::class)->createAttachedNsg(
            $this->apiConfig(),
            $this->firewall['compartment_id'],
            $this->firewall['vcn_id'],
            $this->firewall['vnic_id'],
            $this->firewall['nsg_ids'] ?? [],
            str($this->server->name)->slug()->limit(240, '')->append('-nsg')->toString(),
            $this->server->oci_region,
        );
        if (! empty($result['error'])) {
            $this->dispatch('error', 'Could not create the security group.', e($result['message'] ?? ''));

            return;
        }

        auditLog('ui.oci_nsg.created', ['server_uuid' => $this->server->uuid]);
        $this->selectedNsgId = $result['id'];
        $this->loadNsgRules();
        $this->dispatch('success', 'Security group created and attached to this VM.');
    }

    /**
     * Stack-created VMs keep their firewall in Terraform: rule changes update the desired state and run plan → apply.
     */
    private function terraformStack(): ?OciStack
    {
        return $this->server->ociStack()->where('status', 'provisioned')->whereNotNull('stack_ocid')->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function applyFirewallThroughTerraform(OciStack $stack, array $rules, string $auditAction): void
    {
        $result = $stack->queueFirewallChange($rules);
        if ($result !== 'queued') {
            $this->dispatch('error', OciStack::firewallChangeError($result));

            return;
        }

        auditLog('ui.oci_firewall.'.$auditAction, ['server_uuid' => $this->server->uuid, 'oci_stack_id' => $stack->id]);

        $this->newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];
        $this->dispatch('success', 'Firewall change queued: Terraform runs plan → apply (see Terraform Jobs).');
    }

    /**
     * Attach or detach a shared firewall policy of this VM's team, then let Terraform apply the new effective rules.
     */
    public function toggleFirewallPolicy(int $policyId): void
    {
        $this->authorize('update', $this->server);
        $stack = $this->terraformStack();
        $policy = $stack ? OciFirewallPolicy::query()->where('team_id', $stack->team_id)->find($policyId) : null;
        if (! $policy) {
            return;
        }
        if ($stack->hasRunningJob()) {
            $this->dispatch('error', 'Terraform is still applying the previous change. Try again when it finishes.');

            return;
        }

        $stack->firewallPolicies()->toggle($policy->id);
        $result = $stack->queueFirewallChange();
        if ($result !== 'queued') {
            $stack->firewallPolicies()->toggle($policy->id);
            $this->dispatch('error', OciStack::firewallChangeError($result));

            return;
        }
        auditLog('ui.oci_firewall.policy_toggled', ['server_uuid' => $this->server->uuid, 'oci_firewall_policy_id' => $policy->id]);
        $this->dispatch('success', 'Firewall change queued: Terraform runs plan → apply.');
    }

    /**
     * Roll the firewall back to a previous successful apply of this VM's stack.
     */
    public function restoreFirewallVersion(int $jobId): void
    {
        $this->authorize('update', $this->server);
        $stack = $this->terraformStack();
        $version = $stack?->firewallVersions(100)->firstWhere('id', $jobId);
        if (! $version) {
            $this->dispatch('error', 'That firewall version does not exist for this VM.');

            return;
        }

        // Versions store the effective rules; rules that attached policies still provide stay with the policies.
        $policyKeys = $stack->firewallPolicies()->get()->flatMap(fn ($policy) => OciStack::normalizeFirewallRules($policy->rules ?? []))
            ->map(fn (array $rule): string => OciStack::firewallRuleKey($rule))->all();
        $ownRules = array_values(array_filter($version->firewall_rules, fn (array $rule): bool => ! in_array(OciStack::firewallRuleKey($rule), $policyKeys, true)));

        $this->applyFirewallThroughTerraform($stack, $ownRules, 'version_restored');
    }

    /**
     * The NSG the user edits must be one attached to this VM, never an arbitrary OCID from the browser.
     */
    private function editableNsgId(): ?string
    {
        return in_array($this->selectedNsgId, $this->firewall['nsg_ids'] ?? [], true) ? $this->selectedNsgId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function apiConfig(): array
    {
        return $this->server->ociConnection->toApiConfig($this->server->oci_region);
    }

    public function startInstance(): void
    {
        $this->authorize('update', $this->server);
        $this->performAction('START');
    }

    public function confirmStop(): void
    {
        $this->authorize('update', $this->server);
        $this->confirmingStop = true;
    }

    public function stopInstance(): void
    {
        $this->authorize('update', $this->server);
        $this->confirmingStop = false;
        $this->performAction('SOFTSTOP');
    }

    public function confirmReboot(): void
    {
        $this->authorize('update', $this->server);
        $this->confirmingReboot = true;
    }

    public function rebootInstance(): void
    {
        $this->authorize('update', $this->server);
        $this->confirmingReboot = false;
        $this->performAction('SOFTRESET');
    }

    public function cancelConfirm(): void
    {
        $this->confirmingStop = false;
        $this->confirmingReboot = false;
        $this->confirmingDetach = false;
        $this->pendingDetachId = null;
    }

    public function loadVolumeAttachments(): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;
        $compartmentId = $this->server->oci_compartment_id;

        if (! $connection || ! $instanceId || ! $compartmentId) {
            return;
        }

        $result = app(OciClient::class)->listVolumeAttachments(
            $connection->toApiConfig(),
            $compartmentId,
            $instanceId,
            $this->server->oci_region,
        );

        $this->volumeAttachments = isset($result['error']) ? [] : $result;
    }

    public function loadAvailableVolumes(): void
    {
        $connection = $this->server->ociConnection;
        $compartmentId = $this->server->oci_compartment_id;

        if (! $connection || ! $compartmentId) {
            return;
        }

        $result = app(OciClient::class)->listVolumes(
            $connection->toApiConfig(),
            $compartmentId,
            $this->server->oci_region,
        );

        $this->availableVolumes = isset($result['error']) ? [] : $result;
        $this->showVolumeSelector = true;
    }

    public function createVolume(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'newVolumeName' => 'required|string|max:255',
            'newVolumeSize' => 'required|integer|min:50|max:32768',
        ]);

        $connection = $this->server->ociConnection;
        $compartmentId = $this->server->oci_compartment_id;

        if (! $connection || ! $compartmentId) {
            $this->dispatch('error', 'No OCI compartment linked to this server.');

            return;
        }

        // Block volumes are AD-local: they must live in the instance's AD to be attachable.
        $ad = $this->instanceStatus['availability_domain'] ?? app(OciClient::class)->getInstanceStatus(
            $connection->toApiConfig(),
            $this->server->oci_instance_id,
            $this->server->oci_region,
        )['availability_domain'] ?? null;
        if (! $ad) {
            $this->dispatch('error', 'Could not determine availability domain.');

            return;
        }

        $result = app(OciClient::class)->createVolume(
            $connection->toApiConfig(),
            $compartmentId,
            $ad,
            $this->newVolumeName,
            $this->newVolumeSize,
            $this->server->oci_region,
        );

        if (isset($result['error'])) {
            $this->dispatch('error', 'Volume creation failed. Check OCI console.');

            return;
        }

        $this->showCreateVolume = false;
        $this->newVolumeName = '';
        $this->newVolumeSize = 50;
        $this->dispatch('info', 'Volume "'.$result['display_name'].'" is being provisioned (~30s). Use "Find Volume" to attach it once AVAILABLE.');
    }

    public function attachVolume(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['attachVolumeOcid' => 'required|string|starts_with:ocid1.volume.']);

        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            $this->dispatch('error', 'No OCI instance linked to this server.');

            return;
        }

        $result = app(OciClient::class)->attachVolume(
            $connection->toApiConfig(),
            $instanceId,
            $this->attachVolumeOcid,
            $this->server->oci_region,
        );

        if (isset($result['error'])) {
            $this->dispatch('error', 'Attach failed. Check volume OCID and IAM permissions.');

            return;
        }

        $this->attachVolumeOcid = '';
        $this->loadVolumeAttachments();
    }

    public function confirmDetachVolume(string $attachmentId): void
    {
        $this->authorize('update', $this->server);
        $this->pendingDetachId = $attachmentId;
        $this->confirmingDetach = true;
    }

    public function detachVolume(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->pendingDetachId) {
            return;
        }

        $connection = $this->server->ociConnection;

        if (! $connection) {
            return;
        }

        $result = app(OciClient::class)->detachVolume(
            $connection->toApiConfig(),
            $this->pendingDetachId,
            $this->server->oci_region,
        );

        if (isset($result['error'])) {
            $this->dispatch('error', 'Detach failed. The volume may still be in use.');
        }

        $this->confirmingDetach = false;
        $this->pendingDetachId = null;
        $this->loadVolumeAttachments();
    }

    public function loadLoadBalancers(): void
    {
        $connection = $this->server->ociConnection;
        $compartmentId = $this->server->oci_compartment_id;

        if (! $connection || ! $compartmentId) {
            return;
        }

        $result = app(OciClient::class)->listLoadBalancers(
            $connection->toApiConfig(),
            $compartmentId,
            $this->server->oci_region,
        );

        $this->loadBalancers = isset($result['error']) ? [] : $result;
    }

    public function loadAutonomousDatabases(): void
    {
        $connection = $this->server->ociConnection;
        $compartmentId = $this->server->oci_compartment_id;

        if (! $connection || ! $compartmentId) {
            return;
        }

        $result = app(OciClient::class)->listAutonomousDatabases(
            $connection->toApiConfig(),
            $compartmentId,
            $this->server->oci_region,
        );

        $this->autonomousDatabases = isset($result['error']) ? [] : $result;
    }

    public function loadRouteTables(): void
    {
        $ociStack = $this->server->ociStack()->first();
        $connection = $this->server->ociConnection;

        if (! $connection || ! $ociStack?->vcn_ocid || ! $ociStack?->compartment_ocid) {
            return;
        }

        $result = app(OciClient::class)->listRouteTables(
            $connection->toApiConfig(),
            $ociStack->compartment_ocid,
            $ociStack->vcn_ocid,
            $this->server->oci_region,
        );

        $this->routeTables = isset($result['error']) ? [] : $result;
    }

    public function loadBuckets(): void
    {
        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        // Buckets of the server's compartment (Object Storage lists one compartment per call).
        $result = app(OciClient::class)->listBuckets(
            $connection->toApiConfig(),
            $this->server->oci_compartment_id ?: $connection->tenancy_ocid,
            $this->server->oci_region,
        );

        $this->buckets = isset($result['error']) ? [] : $result;
    }

    private function performAction(string $action): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            $this->dispatch('error', 'No OCI instance linked to this server.');

            return;
        }

        $client = app(OciClient::class);
        $result = $client->instanceAction($connection->toApiConfig(), $instanceId, $this->server->oci_region, $action);

        if (isset($result['lifecycle_state'])) {
            $this->server->update(['oci_instance_status' => $result['lifecycle_state']]);
        } else {
            $this->dispatch('error', 'Action failed. Check OCI console.');
        }

        $this->loadStatus();
    }

    public function addNsgRule(): void
    {
        $this->authorize('update', $this->server);

        $this->validate(OciStack::firewallRuleValidation('newRule'), [
            'newRule.port_min.required_if' => 'TCP and UDP rules need a port.',
            'newRule.port_max.gte' => 'The last port must be greater than or equal to the first port.',
        ]);

        $rule = array_filter($this->newRule, fn ($v): bool => $v !== '' && $v !== null);

        if ($stack = $this->terraformStack()) {
            $this->applyFirewallThroughTerraform($stack, [...($stack->firewall_rules ?? []), $rule], 'rule_added');

            return;
        }

        $nsgId = $this->editableNsgId();
        if (! $nsgId || ! $this->server->ociConnection) {
            $this->dispatch('error', 'No security group attached to this VM. Create one first.');

            return;
        }

        $result = app(OciClient::class)->addNsgRules($this->apiConfig(), $nsgId, [$rule], $this->server->oci_region);

        if (! empty($result['error'])) {
            $this->dispatch('error', 'Could not add the rule.', e($result['message'] ?? ''));

            return;
        }

        auditLog('ui.oci_nsg.rule_added', ['server_uuid' => $this->server->uuid, 'rule' => $rule]);
        $this->newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];
        $this->loadNsgRules();
        $this->dispatch('success', 'Rule added.');
    }

    public function removeNsgRule(string $ruleId): void
    {
        $this->authorize('update', $this->server);

        if ($stack = $this->terraformStack()) {
            $rules = array_values(array_filter(
                OciStack::normalizeFirewallRules($stack->firewall_rules ?? []),
                fn (array $rule): bool => OciStack::firewallRuleKey($rule) !== $ruleId,
            ));
            $this->applyFirewallThroughTerraform($stack, $rules, 'rule_removed');

            return;
        }

        $nsgId = $this->editableNsgId();
        if (! $nsgId || ! $this->server->ociConnection) {
            return;
        }

        $result = app(OciClient::class)->removeNsgRules($this->apiConfig(), $nsgId, [$ruleId], $this->server->oci_region);

        if (! empty($result['error'])) {
            $this->dispatch('error', 'Could not remove the rule.', e($result['message'] ?? ''));

            return;
        }

        auditLog('ui.oci_nsg.rule_removed', ['server_uuid' => $this->server->uuid, 'rule_id' => $ruleId]);
        $this->loadNsgRules();
    }

    public function render(): Factory|View
    {
        $stack = $this->terraformStack();

        return view('livewire.server.oci-dashboard', [
            'terraformStack' => $stack,
            // Desired rules in the table shape; the id is the Terraform resource key.
            'managedRules' => $stack ? array_map(fn (array $rule): array => [
                ...$rule,
                'id' => OciStack::firewallRuleKey($rule),
                'protocol_label' => $rule['protocol'],
            ], OciStack::normalizeFirewallRules($stack->firewall_rules ?? [])) : [],
            'firewallApplying' => (bool) $stack?->hasRunningJob(),
            'attachedPolicies' => $stack?->firewallPolicies()->get() ?? collect(),
            'teamPolicies' => $stack ? OciFirewallPolicy::query()->where('team_id', $stack->team_id)->orderBy('name')->get() : collect(),
            'firewallVersions' => $stack?->firewallVersions() ?? collect(),
        ]);
    }
}
