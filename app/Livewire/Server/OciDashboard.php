<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\OciBridgeClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
        $this->loadProvisioningStatus();
        if (! $this->isProvisioning) {
            $this->loadStatus();
            $this->loadMetrics();
            $this->loadNsgRules();
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

        if (! $this->isProvisioning && $ociStack->status === 'provisioned' && ! $this->instanceStatus) {
            $this->loadStatus();
            $this->loadMetrics();
            $this->loadNsgRules();
        }
    }

    public function getListeners(): array
    {
        return ["echo-private:team.{$this->server->team_id},.ServerStatusChanged" => 'loadStatus'];
    }

    public function loadStatus(): void
    {
        $connection = $this->server->ociConnection;
        $instanceId = $this->server->oci_instance_id;

        if (! $connection || ! $instanceId) {
            return;
        }

        $client = new OciBridgeClient;
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

        $client = new OciBridgeClient;
        $result = $client->getInstanceMetrics(
            $connection->toBridgeConfig(),
            $instanceId,
            $this->server->oci_compartment_id ?? '',
            $this->server->oci_region
        );

        if (! isset($result['cpu_percent'])) {
            return;
        }

        $this->metrics = ($result['cpu_percent'] !== null || $result['memory_percent'] !== null)
            ? $result
            : null;
    }

    public function loadNsgRules(): void
    {
        $ociStack = $this->server->ociStack;
        if (! $ociStack?->nsg_ocid) {
            return;
        }

        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        $client = new OciBridgeClient;
        $result = $client->listNsgRules($connection->toBridgeConfig(), $ociStack->nsg_ocid, $this->server->oci_region);
        $this->nsgRules = $result['data'] ?? [];
    }

    public function startInstance(): void
    {
        $this->performAction('START');
    }

    public function confirmStop(): void
    {
        $this->confirmingStop = true;
    }

    public function stopInstance(): void
    {
        $this->confirmingStop = false;
        $this->performAction('SOFTSTOP');
    }

    public function confirmReboot(): void
    {
        $this->confirmingReboot = true;
    }

    public function rebootInstance(): void
    {
        $this->confirmingReboot = false;
        $this->performAction('SOFTRESET');
    }

    public function cancelConfirm(): void
    {
        $this->confirmingStop = false;
        $this->confirmingReboot = false;
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
        $client = new OciBridgeClient;
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
        $this->validate([
            'newRule.protocol' => 'required|in:TCP,UDP,ICMP,ALL',
            'newRule.port_min' => 'nullable|integer|min:1|max:65535',
            'newRule.port_max' => 'nullable|integer|min:1|max:65535',
            'newRule.source' => 'required|string',
        ]);

        $ociStack = $this->server->ociStack;
        if (! $ociStack?->nsg_ocid) {
            $this->dispatch('error', 'No NSG linked to this server.');

            return;
        }

        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        $rule = array_filter($this->newRule, fn ($v) => $v !== '');
        (new OciBridgeClient)->addNsgRules($connection->toBridgeConfig(), $ociStack->nsg_ocid, [$rule]);

        $this->newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];
        $this->loadNsgRules();
    }

    public function removeNsgRule(string $ruleId): void
    {
        $ociStack = $this->server->ociStack;
        if (! $ociStack?->nsg_ocid) {
            return;
        }

        $connection = $this->server->ociConnection;
        if (! $connection) {
            return;
        }

        (new OciBridgeClient)->removeNsgRules($connection->toBridgeConfig(), $ociStack->nsg_ocid, [$ruleId]);
        $this->loadNsgRules();
    }

    public function render()
    {
        return view('livewire.server.oci-dashboard');
    }
}
