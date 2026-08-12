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
                'fingerprint' => 'required|string|max:255',
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

    public function addConnection(): void
    {
        $this->validate();

        try {
            $credentials = [
                'authentication_method' => $this->authentication_method,
                'region' => $this->region,
            ];

            if ($this->authentication_method === 'api_key') {
                $credentials = array_merge($credentials, [
                    'tenancy_ocid' => $this->tenancy_ocid,
                    'user_ocid' => $this->user_ocid,
                    'fingerprint' => $this->fingerprint,
                    'private_key' => $this->private_key,
                    'passphrase' => $this->passphrase,
                ]);
            }

            $client = app(OciBridgeClient::class);
            $result = $client->validateConnection($credentials);

            if (isset($result['error']) && $result['error']) {
                $this->dispatch('error', $result['message'] ?? 'Validation failed.');

                return;
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
                'private_key' => $this->private_key,
                'passphrase' => $this->passphrase,
            ]);

            auditLog('ui.oci_connection.created', [
                'team_id' => currentTeam()->id,
                'oci_connection_uuid' => $connection->uuid ?? null,
                'oci_connection_name' => $connection->name,
                'auth_method' => $connection->authentication_method->value,
            ]);

            $this->reset([
                'name', 'tenancy_ocid', 'user_ocid', 'fingerprint',
                'private_key', 'passphrase', 'compartment_ocid',
            ]);

            $this->dispatch('connectionAdded');
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
