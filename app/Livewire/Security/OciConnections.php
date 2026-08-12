<?php

namespace App\Livewire\Security;

use App\Models\OciConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OciConnections extends Component
{
    use AuthorizesRequests;

    public $connections;

    public function mount(): void
    {
        try {
            $this->authorize('viewAny', OciConnection::class);
            $this->loadConnections();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function getListeners(): array
    {
        return [
            'connectionAdded' => 'loadConnections',
            'securityResourceChanged' => 'loadConnections',
        ];
    }

    public function loadConnections(): void
    {
        $this->connections = OciConnection::ownedByCurrentTeam()->get();
    }

    public function deleteConnection(int $connectionId): void
    {
        try {
            $connection = OciConnection::ownedByCurrentTeam()->findOrFail($connectionId);
            $this->authorize('delete', $connection);

            $connectionName = $connection->name;
            $connection->delete();
            $this->loadConnections();

            auditLog('ui.oci_connection.deleted', [
                'team_id' => currentTeam()->id,
                'oci_connection_name' => $connectionName,
            ]);

            $this->dispatch('success', 'OCI connection deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.oci-connections');
    }
}
