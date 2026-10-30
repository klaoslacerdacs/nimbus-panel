<?php

namespace App\Livewire\Server;

use App\Models\CloudProviderToken;
use App\Models\OciConnection;
use App\Models\PrivateKey;
use App\Models\Team;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public ?string $selectedType = null;

    public ?string $selectedTokenUuid = null;

    public bool $limit_reached = false;

    public bool $has_hetzner_tokens = false;

    public bool $has_oci_connections = false;

    public function mount(?string $selectedType = null, ?string $selectedTokenUuid = null): void
    {
        $this->selectedType = in_array($selectedType, ['hetzner', 'vultr', 'digital-ocean', 'hostinger', 'manual'], true)
            ? $selectedType
            : null;
        $this->selectedTokenUuid = $this->selectedType && $this->selectedType !== 'manual' ? $selectedTokenUuid : null;

        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
        $this->has_oci_connections = OciConnection::where('team_id', currentTeam()->id)->exists();

        if (! isCloud()) {
            $this->limit_reached = false;

            return;
        }
        $this->limit_reached = Team::serverLimitReached();

        // Check if user has Hetzner tokens
        $this->has_hetzner_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->exists();
    }

    public function render()
    {
        return view('livewire.server.create');
    }
}
