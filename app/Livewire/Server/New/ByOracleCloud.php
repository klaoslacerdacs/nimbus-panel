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

    public ?int $selected_connection_id = null;

    public ?string $selectedConnectionUuid = null;

    public string $region = 'us-ashburn-1';

    public string $compartment_ocid = '';

    public array $availability_domains = [];

    public string $availability_domain = '';

    public array $subnets = [];

    public string $subnet_ocid = '';

    public string $shape = 'VM.Standard.A1.Flex';

    public string $image_id = '';

    public string $server_name = '';

    public ?int $private_key_id = null;

    public string $ssh_username = 'ubuntu';

    public bool $from_onboarding = false;

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
        }

        $this->limit_reached = Team::serverLimitReached();
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
        $this->loadDropdowns();
    }

    public function updatedCompartmentOcid(): void
    {
        $this->loadDropdowns();
    }

    private function loadDropdowns(): void
    {
        if (! $this->selected_connection_id || ! $this->compartment_ocid || ! $this->region) {
            return;
        }

        $connection = OciConnection::ownedByCurrentTeam()->find($this->selected_connection_id);
        if (! $connection) {
            return;
        }

        $client = new OciBridgeClient;
        $bridgeConfig = $connection->toBridgeConfig();

        $adResult = $client->listAvailabilityDomains($bridgeConfig, $this->compartment_ocid, $this->region);
        $this->availability_domains = $adResult['data'] ?? [];
        if ($this->availability_domains && ! $this->availability_domain) {
            $this->availability_domain = $this->availability_domains[0]['name'] ?? '';
        }

        $subnetResult = $client->listSubnetsByCompartment($bridgeConfig, $this->compartment_ocid, $this->region);
        $this->subnets = $subnetResult['data'] ?? [];
        if ($this->subnets && ! $this->subnet_ocid) {
            $this->subnet_ocid = $this->subnets[0]['id'] ?? '';
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

    protected function rules(): array
    {
        $rules = [
            'selected_connection_id' => 'required|integer|exists:oci_connections,id,team_id,'.currentTeam()->id,
        ];

        if ($this->current_step === 2) {
            $rules = array_merge($rules, [
                'server_name' => ['required', 'string', 'max:253', new ValidHostname],
                'region' => 'required|string',
                'compartment_ocid' => 'required|string',
                'availability_domain' => 'required|string',
                'subnet_ocid' => 'required|string',
                'shape' => 'required|string',
                'image_id' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'ssh_username' => 'required|string|max:255',
            ]);
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'selected_connection_id.required' => 'Please select an OCI connection.',
            'selected_connection_id.exists' => 'Selected connection not found.',
        ];
    }

    public function selectConnection(int $connectionId): mixed
    {
        $this->selected_connection_id = $connectionId;

        return $this->nextStep();
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

    public function nextStep(): mixed
    {
        $this->validate([
            'selected_connection_id' => 'required|integer|exists:oci_connections,id,team_id,'.currentTeam()->id,
        ]);

        try {
            $this->current_step = 2;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function previousStep(): mixed
    {
        if ($this->selectedConnectionUuid) {
            return $this->redirectRoute('server.create.type', ['type' => 'oracle-cloud'], navigate: true);
        }

        $this->current_step = 1;

        return null;
    }

    public function createServer(): mixed
    {
        $this->current_step = 2;
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            $connection = OciConnection::ownedByCurrentTeam()->findOrFail($this->selected_connection_id);

            [$server, $ociStack] = DB::transaction(function () {
                $server = Server::create([
                    'name' => strtolower(trim($this->server_name)),
                    'ip' => '', // ponytail: OCI IP assigned after Terraform apply; polled later
                    'port' => 22,
                    'user' => $this->ssh_username,
                    'team_id' => currentTeam()->id,
                    'oci_connection_id' => $this->selected_connection_id,
                    'oci_region' => $this->region,
                    'oci_compartment_id' => $this->compartment_ocid,
                    'private_key_id' => $this->private_key_id,
                    'is_build_server' => false,
                ]);

                $privateKey = PrivateKey::find($this->private_key_id);
                $ociStack = OciStack::create([
                    'server_id' => $server->id,
                    'team_id' => currentTeam()->id,
                    'oci_connection_id' => $this->selected_connection_id,
                    'name' => $this->server_name,
                    'compartment_ocid' => $this->compartment_ocid,
                    'region' => $this->region,
                    'config_source' => 'template_zip',
                    'status' => 'pending',
                    'tf_vars' => [
                        'compartment_ocid' => $this->compartment_ocid,
                        'region' => $this->region,
                        'availability_domain' => $this->availability_domain,
                        'subnet_ocid' => $this->subnet_ocid,
                        'shape' => $this->shape,
                        'image_id' => $this->image_id,
                        'instance_name' => $this->server_name,
                        'ssh_authorized_keys' => $privateKey?->public_key ?? '',
                        'project_tag' => currentTeam()->name,
                        'environment_tag' => 'production',
                    ],
                ]);

                return [$server, $ociStack];
            });

            auditLog('server_created', $server->only(['uuid', 'name', 'team_id', 'oci_region', 'oci_compartment_id']));

            OciStackProvisionJob::dispatch($ociStack);

            $this->dispatch('serverCreated', $server->uuid);

            if ($this->from_onboarding) {
                currentTeam()->update([
                    'show_boarding' => false,
                ]);
                refreshSession();
            }

            return redirectRoute($this, 'server.show', [$server->uuid]);
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
