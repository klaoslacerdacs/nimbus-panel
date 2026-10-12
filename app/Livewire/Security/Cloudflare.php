<?php

namespace App\Livewire\Security;

use App\Models\CloudflareConnection;
use App\Services\CloudflareOAuth;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Nimbus: the team's Cloudflare account, connected through OAuth and used for tunnels and DNS.
 */
class Cloudflare extends Component
{
    public ?string $flashError = null;

    public ?string $flashSuccess = null;

    public function mount(): void
    {
        $this->flashError = session('error');
        $this->flashSuccess = session('success');
    }

    public function disconnect(CloudflareOAuth $oauth): void
    {
        $connection = CloudflareConnection::query()->where('team_id', currentTeam()->id)->first();
        if (! $connection) {
            return;
        }
        abort_unless(auth()->user()->isAdminOfTeam($connection->team_id), 403);

        $oauth->revoke($connection);
        $connection->delete();

        auditLog('ui.team.cloudflare.disconnected', ['team_id' => $connection->team_id, 'account_id' => $connection->account_id]);
        $this->reset('flashError', 'flashSuccess');
        $this->dispatch('success', 'Cloudflare disconnected.');
    }

    public function render(CloudflareOAuth $oauth): Factory|View
    {
        $teamId = currentTeam()->id;

        return view('livewire.security.cloudflare', [
            'connection' => CloudflareConnection::query()->where('team_id', $teamId)->first(),
            'canManage' => auth()->user()->isAdminOfTeam($teamId),
            'isConfigured' => $oauth->isConfigured(),
        ]);
    }
}
