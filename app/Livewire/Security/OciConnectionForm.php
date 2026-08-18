<?php

namespace App\Livewire\Security;

use App\Models\OciConnection;
use App\Services\OciBridgeClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OciConnectionForm extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $authentication_method = 'api_key';

    public string $region = 'us-ashburn-1';

    public ?string $compartment_ocid = null;

    public ?string $tenancy_ocid = null;

    public ?string $user_ocid = null;

    public ?string $fingerprint = null;

    public ?string $private_key = null;

    public ?string $passphrase = null;

    public string $config_snippet = '';

    public function updatedConfigSnippet(string $value): void
    {
        foreach (explode("\n", $value) as $line) {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $k = trim($k);
            $v = trim($v);
            match ($k) {
                'user' => $this->user_ocid = $v,
                'tenancy' => $this->tenancy_ocid = $v,
                'fingerprint' => $this->fingerprint = $v,
                'region' => in_array($v, config('oci.regions')) ? ($this->region = $v) : null,
                default => null,
            };
        }
    }

    public function mount(): void
    {
        try {
            $this->authorize('create', OciConnection::class);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'authentication_method' => 'required|string|in:api_key,instance_principal',
            'region' => 'required|string|max:50',
            'compartment_ocid' => 'nullable|string|max:255',
        ];

        if ($this->authentication_method === 'api_key') {
            $rules = array_merge($rules, [
                'tenancy_ocid' => 'required|string|max:255',
                'user_ocid' => 'required|string|max:255',
                'fingerprint' => ['required', 'string', 'regex:/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/'],
                'private_key' => 'required|string',
                'passphrase' => 'nullable|string',
            ]);
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Connection name is required.',
            'tenancy_ocid.required' => 'Tenancy OCID is required for API Key auth.',
            'user_ocid.required' => 'User OCID is required for API Key auth.',
            'fingerprint.required' => 'Fingerprint is required for API Key auth.',
            'private_key.required' => 'Private key is required for API Key auth.',
        ];
    }

    // Zeros secrets before every snapshot sent to the browser — covers validation errors,
    // bridge failures, and any early return path. User must re-enter PEM on retry.
    public function dehydrate(): void
    {
        $this->private_key = null;
        $this->passphrase = null;
    }

    public function addConnection(): void
    {
        try {
            $this->authorize('create', OciConnection::class);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }

        $this->validate();

        // Capture to locals after validation — dehydrate() ensures they are null in the snapshot.
        $privateKey = $this->private_key;
        $passphrase = $this->passphrase;

        try {
            // Bridge config format matches toBridgeConfig() / _parse_config() key names.
            $bridgeConfig = ['auth_mode' => $this->authentication_method, 'region' => $this->region];

            if ($this->authentication_method === 'api_key') {
                $bridgeConfig = array_merge($bridgeConfig, [
                    'tenancy_id' => $this->tenancy_ocid,
                    'user_id' => $this->user_ocid,
                    'fingerprint' => $this->fingerprint,
                    'key_content' => $privateKey,
                    'passphrase' => $passphrase,
                ]);
            }

            $client = app(OciBridgeClient::class);
            $result = $client->validateConnection($bridgeConfig);

            if (isset($result['error']) && $result['error']) {
                $this->dispatch('error', $result['message'] ?? 'Validation failed.');

                return;
            }

            // Auto-fill from tenancy response if the user left them blank.
            if (empty($this->name) && ! empty($result['tenancy_name'])) {
                $this->name = $result['tenancy_name'];
            }
            if (empty($this->compartment_ocid) && ! empty($result['tenancy_ocid'])) {
                $this->compartment_ocid = $result['tenancy_ocid'];
            }

            $connection = OciConnection::create([
                'team_id' => currentTeam()->id,
                'name' => $this->name,
                'authentication_method' => $this->authentication_method,
                'region' => $this->region,
                'compartment_ocid' => $this->compartment_ocid,
                'tenancy_ocid' => $this->tenancy_ocid,
                'user_ocid' => $this->user_ocid,
                'fingerprint' => $this->fingerprint,
                'private_key' => $privateKey,
                'passphrase' => $passphrase,
            ]);

            auditLog('ui.oci_connection.created', [
                'team_id' => currentTeam()->id,
                'oci_connection_uuid' => $connection->uuid ?? null,
                'oci_connection_name' => $connection->name,
                'auth_method' => $connection->authentication_method->value,
            ]);

            $this->reset(['name', 'tenancy_ocid', 'user_ocid', 'fingerprint', 'compartment_ocid']);

            $this->dispatch('connectionAdded');
            $this->dispatch('close-modal');
            $this->dispatch('success', 'OCI connection added and validated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.oci-connection-form');
    }
}
