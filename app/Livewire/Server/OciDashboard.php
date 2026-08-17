<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\OciBridgeClient;
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

    public bool $confirmingStop = false;

    public bool $confirmingReboot = false;

    public bool $actionLoading = false;

    public array $newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];

    public ?string $stackStatus = null;

    public ?string $stackMessage = null;

    public bool $isProvisioning = false;

    public bool $statusLoadAttempted = false;

    public array $volumeAttachments = [];

    public array $loadBalancers = [];

    public array $autonomousDatabases = [];

    public bool $confirmingDetach = false;

    #[Locked]
    public ?string $pendingDetachId = null;

    public string $attachVolumeOcid = '';

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
        $this->loadProvisioningStatus();
        if (! $this->isProvisioning) {
            $this->loadStatus();
            $this->loadMetrics();
            $this->loadNsgRules();
            $this->loadVolumeAttachments();
            $this->loadLoadBalancers();
            $this->loadAutonomousDatabases();
        }
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

        if ($ociStack->status === 'provisioned' && ! $this->statusLoadAttempted) {
            $this->statusLoadAttempted = true;
            $this->loadStatus();
            $this->loadMetrics();
            $this->loadNsgRules();
            $this->loadVolumeAttachments();
            $this->loadLoadBalancers();
            $this->loadAutonomousDatabases();
        }
    }

    public function getListeners(): array
    {
        return [
            "echo-private:team.{$this->server->team_id},.ServerStatusChanged" => 'loadStatus',
            "echo-private:team.{$this->server->team_id},.OciStackStatusChanged" => 'loadProvisioningStatus',
        ];
    }

    public function loadStatus(): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            return;
        }

        $client = app(OciBridgeClient::class);
        $result = $client->getInstanceStatus($connection->toBridgeConfig(), $instanceId, $this->server->oci_region);
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
            // ponytail: SSH/Sentinel path — delegate to HasMetrics trait when server is functional
            return;
        }

        $client = app(OciBridgeClient::class);
        $result = $client->getInstanceMetrics(
            $connection->toBridgeConfig(),
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

    public function loadNsgRules(): void
    {
        $ociStack = $this->server->ociStack()->first();
        if (! $ociStack?->nsg_ocid) {
            return;
        }

        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        $client = app(OciBridgeClient::class);
        $result = $client->listNsgRules($connection->toBridgeConfig(), $ociStack->nsg_ocid, $this->server->oci_region);
        $this->nsgRules = isset($result['error']) ? [] : $result;
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

        $result = app(OciBridgeClient::class)->listVolumeAttachments(
            $connection->toBridgeConfig(),
            $compartmentId,
            $instanceId,
            $this->server->oci_region,
        );

        $this->volumeAttachments = isset($result['error']) ? [] : $result;
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

        $result = app(OciBridgeClient::class)->attachVolume(
            $connection->toBridgeConfig(),
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

        $result = app(OciBridgeClient::class)->detachVolume(
            $connection->toBridgeConfig(),
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

        $result = app(OciBridgeClient::class)->listLoadBalancers(
            $connection->toBridgeConfig(),
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

        $result = app(OciBridgeClient::class)->listAutonomousDatabases(
            $connection->toBridgeConfig(),
            $compartmentId,
            $this->server->oci_region,
        );

        $this->autonomousDatabases = isset($result['error']) ? [] : $result;
    }

    private function performAction(string $action): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            $this->dispatch('error', 'No OCI instance linked to this server.');

            return;
        }

        $this->actionLoading = true;
        $client = app(OciBridgeClient::class);
        $result = $client->instanceAction($connection->toBridgeConfig(), $instanceId, $this->server->oci_region, $action);

        if (isset($result['lifecycle_state'])) {
            $this->server->update(['oci_instance_status' => $result['lifecycle_state']]);
        } else {
            $this->dispatch('error', 'Action failed. Check OCI console.');
        }

        $this->actionLoading = false;
        $this->loadStatus();
    }

    public function addNsgRule(): void
    {
        $this->authorize('update', $this->server);

        foreach (['port_min', 'port_max'] as $portKey) {
            if ($this->newRule[$portKey] === '') {
                $this->newRule[$portKey] = null;
            }
        }

        $this->validate([
            'newRule.protocol' => 'required|in:TCP,UDP,ICMP,ALL',
            'newRule.port_min' => 'nullable|integer|min:1|max:65535',
            'newRule.port_max' => 'nullable|integer|min:1|max:65535',
            'newRule.source' => ['required', 'string', 'regex:/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)\/(3[0-2]|[12]?\d)$/'],
        ]);

        $ociStack = $this->server->ociStack()->first();
        if (! $ociStack?->nsg_ocid) {
            $this->dispatch('error', 'No NSG linked to this server.');

            return;
        }

        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        $rule = array_filter($this->newRule, fn ($v) => $v !== '' && $v !== null);
        $result = app(OciBridgeClient::class)->addNsgRules($connection->toBridgeConfig(), $ociStack->nsg_ocid, [$rule], $connection->region);

        if (isset($result['error'])) {
            $this->dispatch('error', 'Failed to update firewall rules.');

            return;
        }

        $this->newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];
        $this->loadNsgRules();
    }

    public function removeNsgRule(string $ruleId): void
    {
        $this->authorize('update', $this->server);
        $ociStack = $this->server->ociStack()->first();
        if (! $ociStack?->nsg_ocid) {
            return;
        }

        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        $result = app(OciBridgeClient::class)->removeNsgRules($connection->toBridgeConfig(), $ociStack->nsg_ocid, [$ruleId], $connection->region);

        if (isset($result['error'])) {
            $this->dispatch('error', 'Failed to update firewall rules.');

            return;
        }

        $this->loadNsgRules();
    }

    public function render()
    {
        return view('livewire.server.oci-dashboard');
    }
}
