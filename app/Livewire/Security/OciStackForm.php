<?php

namespace App\Livewire\Security;

use App\Models\OciConnection;
use App\Models\OciStack;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

class OciStackForm extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $config_source = 'template_zip';

    public ?string $config_url = null;

    public string $compartment_ocid = '';

    public string $region = 'us-ashburn-1';

    public int $oci_connection_id = 0;

    public function mount(): void
    {
        try {
            $this->authorize('create', OciStack::class);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'oci_connection_id' => ['required', Rule::exists('oci_connections', 'id')->where('team_id', currentTeam()->id)],
            'config_source' => 'required|string|in:template_zip,git_repo,compartment_discovery',
            'config_url' => 'nullable|required_if:config_source,template_zip,git_repo|url',
            'compartment_ocid' => 'required|string|max:255',
            'region' => 'required|string|max:50',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Stack name is required.',
            'oci_connection_id.required' => 'OCI connection is required.',
            'oci_connection_id.exists' => 'OCI connection is required.',
            'config_url.required_if' => 'Config URL is required for template zip or git repo.',
            'compartment_ocid.required' => 'Compartment OCID is required.',
        ];
    }

    public function createStack(): void
    {
        $this->authorize('create', OciStack::class);
        $this->validate();

        try {
            $stack = OciStack::create([
                'team_id' => currentTeam()->id,
                'oci_connection_id' => $this->oci_connection_id,
                'name' => $this->name,
                'config_source' => $this->config_source,
                'config_url' => $this->config_url,
                'compartment_ocid' => $this->compartment_ocid,
                'region' => $this->region,
                'status' => 'pending',
            ]);

            auditLog('ui.oci_stack.created', [
                'team_id' => currentTeam()->id,
                'oci_stack_id' => $stack->id,
                'name' => $stack->name,
                'config_source' => $stack->config_source,
            ]);

            $this->reset(['name', 'oci_connection_id', 'config_source', 'config_url', 'compartment_ocid', 'region']);

            $this->dispatch('stackCreated');
            $this->dispatch('close-modal');
            $this->dispatch('success', 'OCI stack created.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.security.oci-stack-form', [
            'connections' => OciConnection::ownedByCurrentTeam()->get(),
        ]);
    }
}
