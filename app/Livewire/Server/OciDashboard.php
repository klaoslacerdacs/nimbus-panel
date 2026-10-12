<?php

namespace App\Livewire\Server;

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
        if (! $this->isProvisioning) {
            $this->loadStatus();
            $this->loadMetrics();
            $this->loadNsgRules();
            $this->loadVolumeAttachments();
            $this->loadLoadBalancers();
            $this->loadAutonomousDatabases();
            $this->loadRouteTables();
            $this->loadBuckets();
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
            $this->loadRouteTables();
            $this->loadBuckets();
        }
    }

    #[\Override]
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

        $client = app(OciClient::class);
        $result = $client->listNsgRules($connection->toApiConfig(), $ociStack->nsg_ocid, $this->server->oci_region);
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

        $this->actionLoading = true;
        $client = app(OciClient::class);
        $result = $client->instanceAction($connection->toApiConfig(), $instanceId, $this->server->oci_region, $action);

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

        $rule = array_filter($this->newRule, fn ($v): bool => $v !== '' && $v !== null);
        $result = app(OciClient::class)->addNsgRules($connection->toApiConfig(), $ociStack->nsg_ocid, [$rule], $this->server->oci_region);

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

        $result = app(OciClient::class)->removeNsgRules($connection->toApiConfig(), $ociStack->nsg_ocid, [$ruleId], $this->server->oci_region);

        if (isset($result['error'])) {
            $this->dispatch('error', 'Failed to update firewall rules.');

            return;
        }

        $this->loadNsgRules();
    }

    public function render(): Factory|View
    {
        return view('livewire.server.oci-dashboard');
    }
}
