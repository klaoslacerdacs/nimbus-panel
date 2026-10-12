<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\OciConnection;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidHostname;
use App\Rules\ValidServerIp;
use App\Services\OciClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ImportOciServer extends Component
{
    use AuthorizesRequests;

    public int $current_step = 1;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    // Step 1 — Connection + region + compartment
    public ?int $oci_connection_id = null;

    public string $region = 'sa-saopaulo-1';

    #[Locked]
    public array $regions = [];

    public string $compartment_id = '';

    #[Locked]
    public array $compartments = [];

    // Step 2 — Instance selection
    #[Locked]
    public array $instances = [];

    public string $oci_instance_id = '';

    public ?string $oci_instance_status = null;

    public string $instance_display_name = '';

    // Step 3 — Server config
    public string $server_name = '';

    public string $ssh_username = 'ubuntu';

    public ?int $private_key_id = null;

    public string $ip = '';

    public string $oci_metrics_source = 'oci_monitoring';

    public function mount(): void
    {
        $this->authorize('viewAny', OciConnection::class);
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        if ($this->private_keys->count() > 0) {
            $this->private_key_id = $this->private_keys->first()->id;
        }
        $this->limit_reached = Team::serverLimitReached();
        $this->loadRegions();
    }

    public function getListeners(): array
    {
        return [
            'privateKeyCreated' => 'handlePrivateKeyCreated',
        ];
    }

    public function handlePrivateKeyCreated($keyId): void
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    public function updatedOciConnectionId(): void
    {
        $this->region = 'sa-saopaulo-1';
        $this->compartment_id = '';
        $this->compartments = [];
        $this->loadCompartments();
    }

    public function updatedRegion(): void
    {
        $this->compartment_id = '';
        $this->compartments = [];
        $this->loadCompartments();
    }

    private function getConnection(): ?OciConnection
    {
        if (! $this->oci_connection_id) {
            return null;
        }

        return OciConnection::ownedByCurrentTeam()->find($this->oci_connection_id);
    }

    private function loadRegions(): void
    {
        $result = app(OciClient::class)->listRegions();
        if (isset($result['error'])) {
            return;
        }

        $names = array_column($result, 'name');
        sort($names);
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

        $result = app(OciClient::class)->listCompartments($connection->toApiConfig(), $this->region);
        $this->compartments = isset($result['error']) ? [] : $result;
    }

    private function loadInstances(): void
    {
        $connection = $this->getConnection();
        if (! $connection || ! $this->compartment_id) {
            $this->instances = [];

            return;
        }

        $result = app(OciClient::class)->listInstances($connection->toApiConfig(), $this->compartment_id, $this->region);
        $this->instances = isset($result['error'])
            ? []
            : array_values(array_filter($result, fn ($i) => ! in_array($i['lifecycle_state'] ?? '', ['TERMINATED', 'TERMINATING'], true)));
    }

    public function selectInstance(string $instanceId): void
    {
        $instance = collect($this->instances)->firstWhere('id', $instanceId);
        if (! $instance) {
            return;
        }

        $this->oci_instance_id = $instanceId;
        $this->oci_instance_status = $instance['lifecycle_state'] ?? null;
        $this->instance_display_name = $instance['display_name'] ?? '';
        $this->server_name = strtolower(trim($this->instance_display_name ?: generate_random_name()));

        $connection = $this->getConnection();
        if ($connection) {
            $vnic = app(OciClient::class)->getInstancePrimaryVnic($connection->toApiConfig(), $this->compartment_id, $instanceId, $this->region);
            if (! isset($vnic['error'])) {
                $this->ip = $vnic['public_ip'] ?? $vnic['private_ip'] ?? '';
            }
        }
    }

    protected function stepRules(): array
    {
        return [
            1 => [
                'oci_connection_id' => 'required|integer|exists:oci_connections,id,team_id,'.currentTeam()->id,
                'region' => 'required|string',
                // Listed compartments are authoritative; when listing is not permitted the user types the OCID.
                'compartment_id' => ['required', 'string', $this->compartments ? Rule::in(array_column($this->compartments, 'id')) : 'regex:/^ocid1\.(compartment|tenancy)\.oc1\./'],
            ],
            2 => [
                'oci_instance_id' => ['required', 'string', Rule::in(array_column($this->instances, 'id'))],
            ],
            3 => [
                'server_name' => ['required', 'string', 'max:253', new ValidHostname],
                'ssh_username' => 'required|string|max:255',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'ip' => ['required', 'string', new ValidServerIp],
                'oci_metrics_source' => 'required|in:oci_monitoring,sentinel',
            ],
        ];
    }

    public function nextStep(): mixed
    {
        $this->validate($this->stepRules()[$this->current_step] ?? []);

        try {
            if ($this->current_step === 1) {
                $this->loadInstances();
            }
            $this->current_step++;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function previousStep(): void
    {
        if ($this->current_step > 1) {
            $this->current_step--;
        }
    }

    public function createServer(): mixed
    {
        $allRules = array_merge(...array_values($this->stepRules()));
        $this->validate($allRules);

        try {
            $this->authorize('create', Server::class);

            $teamId = currentTeam()->id;

            if (Server::whereIp($this->ip)->whereTeamId($teamId)->exists()) {
                $this->dispatch('warning', 'A server with this IP already exists in your team.');
            }

            if (Server::where('oci_instance_id', $this->oci_instance_id)->exists()) {
                return $this->dispatch('error', 'This instance is already imported.');
            }

            $connection = OciConnection::ownedByCurrentTeam()->findOrFail($this->oci_connection_id);

            $server = Team::createServerWithinLimit($teamId, [
                'name' => strtolower(trim($this->server_name)),
                'ip' => $this->ip,
                'port' => 22,
                'user' => $this->ssh_username,
                'team_id' => $teamId,
                'private_key_id' => $this->private_key_id,
                'oci_connection_id' => $connection->id,
                'oci_instance_id' => $this->oci_instance_id,
                'oci_instance_status' => $this->oci_instance_status,
                'oci_region' => $this->region,
                'oci_compartment_id' => $this->compartment_id,
                'oci_metrics_source' => $this->oci_metrics_source,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();
            $server->settings()->update(['is_reachable' => false, 'is_usable' => false]);

            auditLog('server_imported', $server->only(['uuid', 'name', 'team_id', 'oci_region', 'oci_instance_id']));

            ValidateAndInstallServerJob::dispatch($server);

            $this->dispatch('serverCreated', $server->uuid);

            return redirectRoute($this, 'server.oci', [$server->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.import-oci-server', [
            'connections' => OciConnection::ownedByCurrentTeam()->get(),
        ]);
    }
}
