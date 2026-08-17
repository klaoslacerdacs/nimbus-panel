<?php

namespace App\Livewire\Server\New;

use App\Jobs\OciStackProvisionJob;
use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidHostname;
use App\Services\OciBridgeClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByOracleCloud extends Component
{
    use AuthorizesRequests;

    public int $current_step = 1;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    // Step 1 — Connection
    public ?int $selected_connection_id = null;

    public ?string $selectedConnectionUuid = null;

    public bool $from_onboarding = false;

    // Step 2 — Region + Compartment
    public string $region = 'sa-saopaulo-1';

    public array $regions = [];

    public bool $create_compartment = false;

    public string $compartment_ocid = '';

    public string $compartment_name = '';

    public string $parent_compartment_ocid = '';

    public array $compartments = [];

    // Step 3 — Network
    public bool $create_network = false;

    public string $vcn_ocid = '';

    public array $vcns = [];

    public string $subnet_ocid = '';

    public array $subnets = [];

    public string $vcn_display_name = '';

    public string $vcn_dns_label = '';

    public string $vcn_cidr = '10.0.0.0/16';

    public string $subnet_cidr = '';

    // Firewall rules (ingress, applied to NSG post-provision)
    public array $firewall_rules = [
        ['protocol' => 'TCP', 'port_min' => 22, 'port_max' => 22, 'source' => '0.0.0.0/0', 'description' => 'SSH'],
        ['protocol' => 'TCP', 'port_min' => 80, 'port_max' => 80, 'source' => '0.0.0.0/0', 'description' => 'HTTP'],
        ['protocol' => 'TCP', 'port_min' => 443, 'port_max' => 443, 'source' => '0.0.0.0/0', 'description' => 'HTTPS'],
    ];

    // Step 4 — Compute
    public array $availability_domains = [];

    public string $availability_domain = '';

    public array $shapes = [];

    public string $shape = 'VM.Standard.A1.Flex';

    public array $images = [];

    public string $image_id = '';

    public string $server_name = '';

    public ?int $private_key_id = null;

    public string $ssh_username = 'ubuntu';

    public int $ocpus = 1;

    public int $memory_in_gbs = 6;

    public int $boot_volume_size_in_gbs = 50;

    public string $oci_metrics_source = 'oci_monitoring';

    public function mount(?string $selectedConnectionUuid = null): void
    {
        $this->authorize('viewAny', OciConnection::class);
        $this->selectConnectionFromUrl($selectedConnectionUuid);
        $this->server_name = generate_random_name();
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

        if ($this->private_keys->count() > 0) {
            $this->private_key_id = $this->private_keys->first()->id;
        }

        if ($this->selectedConnectionUuid) {
            $this->current_step = 2;
            $this->loadCompartments();
        }

        $this->limit_reached = Team::serverLimitReached();
        $this->loadRegions();
    }

    public function getListeners(): array
    {
        return [
            'ociConnectionCreated' => 'handleConnectionCreated',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function resetSelection(): void
    {
        $this->selected_connection_id = null;
        $this->current_step = 1;
    }

    public function updatedRegion(): void
    {
        $this->compartments = [];
        $this->compartment_ocid = '';
        $this->vcns = [];
        $this->vcn_ocid = '';
        $this->subnets = [];
        $this->subnet_ocid = '';
        $this->loadCompartments();
    }

    public function updatedCompartmentOcid(): void
    {
        $this->vcns = [];
        $this->vcn_ocid = '';
        $this->subnets = [];
        $this->subnet_ocid = '';
        $this->loadVcns();
    }

    public function updatedVcnOcid(): void
    {
        $this->subnets = [];
        $this->subnet_ocid = '';
        if ($this->vcn_ocid) {
            $this->loadSubnets();
        }
    }

    public function updatedCreateCompartment(): void
    {
        $this->compartment_ocid = '';
        $this->compartment_name = '';
    }

    public function updatedCreateNetwork(): void
    {
        $this->vcn_ocid = '';
        $this->subnet_ocid = '';
        $this->vcn_display_name = '';
        $this->vcn_cidr = '10.0.0.0/16';
        $this->subnet_cidr = '';
    }

    public function addFirewallRule(): void
    {
        if (count($this->firewall_rules) >= 20) {
            return;
        }

        $this->firewall_rules[] = ['protocol' => 'TCP', 'port_min' => 80, 'port_max' => 80, 'source' => '0.0.0.0/0', 'description' => ''];
    }

    public function removeFirewallRule(int $index): void
    {
        if ($index < 0 || $index >= count($this->firewall_rules)) {
            return;
        }

        array_splice($this->firewall_rules, $index, 1);
        $this->firewall_rules = array_values($this->firewall_rules);
    }

    private function getConnection(): ?OciConnection
    {
        if (! $this->selected_connection_id) {
            return null;
        }

        return OciConnection::ownedByCurrentTeam()->find($this->selected_connection_id);
    }

    private function loadRegions(): void
    {
        $result = (new OciBridgeClient)->listRegions();
        if (isset($result['error'])) {
            return;
        }

        $names = array_column($result, 'name');
        sort($names);

        // sa-saopaulo-1 always first
        $names = array_values(array_filter($names, fn ($n) => $n !== 'sa-saopaulo-1'));
        array_unshift($names, 'sa-saopaulo-1');

        $this->regions = $names;
    }

    private function loadCompartments(): void
    {
        $connection = $this->getConnection();
        if (! $connection || ! $this->region) {
            return;
        }

        $client = new OciBridgeClient;
        $result = $client->listCompartments($connection->toBridgeConfig(), $this->region);
        $this->compartments = isset($result['error']) ? [] : $result;
    }

    private function loadVcns(): void
    {
        if ($this->create_compartment || ! $this->compartment_ocid) {
            return;
        }

        $connection = $this->getConnection();
        if (! $connection) {
            return;
        }

        $client = new OciBridgeClient;
        $result = $client->listVcns($connection->toBridgeConfig(), $this->compartment_ocid, $this->region);
        $this->vcns = isset($result['error']) ? [] : $result;
    }

    private function loadSubnets(): void
    {
        if ($this->create_network || ! $this->vcn_ocid || ! $this->compartment_ocid) {
            return;
        }

        $connection = $this->getConnection();
        if (! $connection) {
            return;
        }

        $client = new OciBridgeClient;
        $result = $client->listSubnets($connection->toBridgeConfig(), $this->vcn_ocid, $this->compartment_ocid, $this->region);
        $this->subnets = isset($result['error']) ? [] : $result;
        if ($this->subnets && ! $this->subnet_ocid) {
            $this->subnet_ocid = $this->subnets[0]['id'] ?? '';
        }
    }

    private function loadComputeDropdowns(): void
    {
        $connection = $this->getConnection();
        if (! $connection) {
            return;
        }

        // ADs are tenancy-wide; any valid compartment works — fall back to parent or tenancy root
        $compartmentId = $this->create_compartment
            ? ($this->parent_compartment_ocid ?: $connection->tenancy_ocid)
            : $this->compartment_ocid;

        if (! $compartmentId) {
            return;
        }

        $client = new OciBridgeClient;
        $config = $connection->toBridgeConfig();

        $adResult = $client->listAvailabilityDomains($config, $compartmentId, $this->region);
        $this->availability_domains = isset($adResult['error']) ? [] : $adResult;
        if ($this->availability_domains && ! $this->availability_domain) {
            $this->availability_domain = $this->availability_domains[0]['name'] ?? '';
        }

        // Reset region-scoped selections so stale OCIDs from a previous region/compartment are not submitted
        $this->image_id = '';
        $this->availability_domain = '';

        $shapeResult = $client->listShapes($config, $compartmentId, $this->region);
        if (! isset($shapeResult['error'])) {
            // Group: Ampere (ARM) → AMD → Intel/Optimized → BM, alphabetical within group
            // BM check must precede .E check — BM.Standard.E* shapes contain ".E"
            $family = fn (string $name): int => match (true) {
                str_contains($name, '.A1.') || str_contains($name, '.A2.') || str_contains($name, '.A4.') => 0,
                str_starts_with($name, 'BM.') => 3,
                str_contains($name, '.E') => 1,
                str_contains($name, 'Standard3') || str_contains($name, 'Standard2') || str_contains($name, 'Optimized') => 2,
                default => 4,
            };
            usort($shapeResult, fn ($a, $b) => $family($a['name']) <=> $family($b['name']) ?: strcmp($a['name'], $b['name']));
            $this->shapes = $shapeResult;
        }

        $imageResult = $client->listImages($config, $compartmentId, $this->region);
        if (! isset($imageResult['error'])) {
            // Group: Ubuntu first (Coolify-preferred), then Rocky, then Oracle Linux
            $order = ['Canonical Ubuntu' => 0, 'Rocky Linux' => 1, 'Oracle Linux' => 2];
            usort($imageResult, fn ($a, $b) => ($order[$a['operating_system']] ?? 9) <=> ($order[$b['operating_system']] ?? 9));
            $this->images = $imageResult;
            // Default: latest Ubuntu
            $ubuntu = collect($this->images)->firstWhere('operating_system', 'Canonical Ubuntu');
            if ($ubuntu && ! $this->image_id) {
                $this->image_id = $ubuntu['id'];
            }
        }
    }

    public function handleConnectionCreated($connectionId): void
    {
        $this->selected_connection_id = $connectionId;
        $this->nextStep();
    }

    public function handlePrivateKeyCreated($keyId): void
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    protected function stepRules(): array
    {
        $connectionRule = 'required|integer|exists:oci_connections,id,team_id,'.currentTeam()->id;

        return [
            1 => ['selected_connection_id' => $connectionRule],
            2 => [
                'selected_connection_id' => $connectionRule,
                'region' => 'required|string',
                'compartment_ocid' => $this->create_compartment ? 'nullable' : 'required|string',
                'compartment_name' => $this->create_compartment ? 'required|string|max:100' : 'nullable',
            ],
            3 => [
                'create_network' => 'boolean',
                'vcn_ocid' => $this->create_network ? 'nullable' : 'required|string',
                'subnet_ocid' => $this->create_network ? 'nullable' : 'required|string',
                'vcn_display_name' => $this->create_network ? 'required|string|max:255' : 'nullable',
                'vcn_cidr' => $this->create_network ? ['required', 'regex:/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)\/(3[0-2]|[12]?\d)$/'] : 'nullable',
                'firewall_rules' => 'array',
                'firewall_rules.*.protocol' => 'required|in:TCP,UDP,ICMP,ALL',
                'firewall_rules.*.port_min' => 'nullable|integer|min:1|max:65535',
                'firewall_rules.*.port_max' => 'nullable|integer|min:1|max:65535',
                'firewall_rules.*.source' => ['required', 'string', 'regex:/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)\/(3[0-2]|[12]?\d)$/'],
            ],
            4 => [
                'server_name' => ['required', 'string', 'max:253', new ValidHostname],
                'availability_domain' => 'required|string',
                'shape' => 'required|string',
                'image_id' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'ssh_username' => 'required|string|max:255',
                'oci_metrics_source' => 'required|in:oci_monitoring,sentinel',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'selected_connection_id.required' => 'Please select an OCI connection.',
            'selected_connection_id.exists' => 'Selected connection not found.',
            'compartment_ocid.required' => 'Select a compartment or enable "Create new".',
            'compartment_name.required' => 'Enter a name for the new compartment.',
            'vcn_ocid.required' => 'Select a VCN or enable "Create new network".',
            'subnet_ocid.required' => 'Select a subnet or enable "Create new network".',
            'vcn_display_name.required' => 'Enter a VCN name.',
            'vcn_cidr.regex' => 'Enter a valid CIDR block (e.g. 10.0.0.0/16).',
        ];
    }

    private function selectConnectionFromUrl(?string $selectedConnectionUuid): void
    {
        if (! $selectedConnectionUuid) {
            return;
        }

        $connection = OciConnection::ownedByCurrentTeam()->where('uuid', $selectedConnectionUuid)->first();

        if (! $connection) {
            return;
        }

        $this->selectedConnectionUuid = $selectedConnectionUuid;
        $this->selected_connection_id = $connection->id;
    }

    public function selectConnection(int $connectionId): mixed
    {
        $this->selected_connection_id = $connectionId;

        return $this->nextStep();
    }

    public function nextStep(): mixed
    {
        $rules = $this->stepRules()[$this->current_step] ?? [];
        $this->validate($rules, $this->messages());

        try {
            if ($this->current_step === 2) {
                $this->loadVcns();
            }

            if ($this->current_step === 3) {
                $this->loadComputeDropdowns();
            }

            $this->current_step++;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function previousStep(): mixed
    {
        if ($this->current_step <= 1) {
            if ($this->selectedConnectionUuid) {
                return $this->redirectRoute('server.create.type', ['type' => 'oracle-cloud'], navigate: true);
            }

            return null;
        }

        $this->current_step--;

        return null;
    }

    public function createServer(): mixed
    {
        $allRules = array_merge(...array_values($this->stepRules()));
        $this->validate($allRules, $this->messages());

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            foreach ($this->firewall_rules as $i => $rule) {
                if (isset($rule['port_min'], $rule['port_max']) && (int) $rule['port_max'] < (int) $rule['port_min']) {
                    $this->addError("firewall_rules.{$i}.port_max", 'Port max must be ≥ port min.');

                    return null;
                }
            }

            $connection = OciConnection::ownedByCurrentTeam()->findOrFail($this->selected_connection_id);
            $privateKey = PrivateKey::ownedAndOnlySShKeys()->findOrFail($this->private_key_id);
            if (! $privateKey->public_key) {
                return $this->dispatch('error', 'Selected key has no public key material.');
            }

            $compartmentOcid = $this->create_compartment ? '' : $this->compartment_ocid;

            $tfVars = array_filter([
                'region' => $this->region,
                'create_compartment' => $this->create_compartment ? 'true' : 'false',
                'compartment_ocid' => $this->create_compartment ? '' : $this->compartment_ocid,
                'compartment_name' => $this->compartment_name,
                'parent_compartment_ocid' => $this->parent_compartment_ocid,
                'create_network' => $this->create_network ? 'true' : 'false',
                'vcn_ocid' => $this->create_network ? '' : $this->vcn_ocid,
                'subnet_ocid' => $this->create_network ? '' : $this->subnet_ocid,
                'vcn_display_name' => $this->vcn_display_name,
                'vcn_dns_label' => $this->vcn_dns_label,
                'vcn_cidr' => $this->vcn_cidr,
                'subnet_cidr' => $this->subnet_cidr,
                'availability_domain' => $this->availability_domain,
                'shape' => $this->shape,
                'image_id' => $this->image_id,
                'instance_display_name' => $this->server_name,
                'ssh_authorized_keys' => $privateKey->public_key,
                'ocpus' => (string) $this->ocpus,
                'memory_in_gbs' => (string) $this->memory_in_gbs,
                'boot_volume_size_in_gbs' => (string) $this->boot_volume_size_in_gbs,
                'project_tag' => currentTeam()->name,
                'environment_tag' => 'production',
            ], fn ($v) => $v !== '');

            [$server, $ociStack] = DB::transaction(function () use ($compartmentOcid, $tfVars) {
                $server = Server::create([
                    'name' => strtolower(trim($this->server_name)),
                    'ip' => '', // ponytail: filled after Terraform apply
                    'port' => 22,
                    'user' => $this->ssh_username,
                    'team_id' => currentTeam()->id,
                    'oci_connection_id' => $this->selected_connection_id,
                    'oci_region' => $this->region,
                    'oci_compartment_id' => $compartmentOcid,
                    'oci_metrics_source' => $this->oci_metrics_source,
                    'private_key_id' => $this->private_key_id,
                    'is_build_server' => false,
                ]);

                $ociStack = OciStack::create([
                    'server_id' => $server->id,
                    'team_id' => currentTeam()->id,
                    'oci_connection_id' => $this->selected_connection_id,
                    'name' => $this->server_name,
                    'compartment_ocid' => $compartmentOcid,
                    'region' => $this->region,
                    'config_source' => 'template_zip',
                    'status' => 'pending',
                    'tf_vars' => $tfVars,
                    'firewall_rules' => $this->firewall_rules,
                ]);

                return [$server, $ociStack];
            });

            auditLog('server_created', $server->only(['uuid', 'name', 'team_id', 'oci_region', 'oci_compartment_id']));

            OciStackProvisionJob::dispatch($ociStack)->afterCommit();

            $this->dispatch('serverCreated', $server->uuid);

            if ($this->from_onboarding) {
                currentTeam()->update(['show_boarding' => false]);
                refreshSession();
            }

            return redirectRoute($this, 'server.oci', [$server->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.by-oracle-cloud', [
            'connections' => OciConnection::ownedByCurrentTeam()->get(),
        ]);
    }
}
