<?php

namespace App\Livewire\Security;

use App\Models\OciConnection;
use App\Services\OciBridgeClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OciConnectionEdit extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $connectionId;

    public string $name = '';

    public string $authentication_method = 'api_key';

    public string $region = 'us-ashburn-1';

    public ?string $compartment_ocid = null;

    public ?string $tenancy_ocid = null;

    public ?string $user_ocid = null;

    public ?string $fingerprint = null;

    // Leave blank to keep existing PEM; fill to rotate.
    public ?string $private_key = null;

    public ?string $passphrase = null;

    public function mount(OciConnection $connection): void
    {
        try {
            $this->authorize('update', $connection);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }

        $this->connectionId = $connection->id;
        $this->name = $connection->name;
        $this->authentication_method = $connection->authentication_method->value;
        $this->region = $connection->region;
        $this->compartment_ocid = $connection->compartment_ocid;
        $this->tenancy_ocid = $connection->tenancy_ocid;
        $this->user_ocid = $connection->user_ocid;
        $this->fingerprint = $connection->fingerprint;
        // private_key intentionally not loaded — never sent to browser.
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
                // blank = keep existing PEM; non-blank = rotate
                'private_key' => 'nullable|string',
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
            'fingerprint.regex' => 'Fingerprint must be 16 colon-separated hex pairs.',
        ];
    }

    // Zeros PEM before every snapshot — same security guarantee as OciConnectionForm.
    public function dehydrate(): void
    {
        $this->private_key = null;
        $this->passphrase = null;
    }

    public function updateConnection(): void
    {
        try {
            $connection = OciConnection::ownedByCurrentTeam()->findOrFail($this->connectionId);
            $this->authorize('update', $connection);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }

        $this->validate();

        // Capture after validation; dehydrate() zeroes these in the snapshot.
        $newKey = $this->private_key;
        $newPassphrase = $this->passphrase;

        // Fall back to existing encrypted PEM when user leaves the field blank.
        $activeKey = $newKey ?: $connection->private_key;
        $activePassphrase = isset($newKey) && $newKey !== '' ? $newPassphrase : $connection->passphrase;

        try {
            $bridgeConfig = ['auth_mode' => $this->authentication_method, 'region' => $this->region];

            if ($this->authentication_method === 'api_key') {
                $bridgeConfig = array_merge($bridgeConfig, [
                    'tenancy_id' => $this->tenancy_ocid,
                    'user_id' => $this->user_ocid,
                    'fingerprint' => $this->fingerprint,
                    'key_content' => $activeKey,
                    'passphrase' => $activePassphrase,
                ]);
            }

            $client = app(OciBridgeClient::class);
            $result = $client->validateConnection($bridgeConfig);

            if (isset($result['error']) && $result['error']) {
                $this->dispatch('error', $result['message'] ?? 'Validation failed.');

                return;
            }

            $updateData = [
                'name' => $this->name,
                'authentication_method' => $this->authentication_method,
                'region' => $this->region,
                'compartment_ocid' => $this->compartment_ocid,
                'tenancy_ocid' => $this->tenancy_ocid,
                'user_ocid' => $this->user_ocid,
                'fingerprint' => $this->fingerprint,
            ];

            // Only update PEM columns when user supplied a new key.
            if ($newKey) {
                $updateData['private_key'] = $newKey;
                $updateData['passphrase'] = $newPassphrase;
            }

            $connection->update($updateData);

            auditLog('ui.oci_connection.updated', [
                'team_id' => currentTeam()->id,
                'oci_connection_id' => $connection->id,
                'oci_connection_name' => $connection->name,
                'auth_method' => $connection->authentication_method->value,
                'key_rotated' => (bool) $newKey,
            ]);

            $this->dispatch('success', 'OCI connection updated and validated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.oci-connection-edit');
    }
}
