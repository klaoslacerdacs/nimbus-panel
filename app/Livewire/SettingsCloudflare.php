<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Nimbus: the Cloudflare OAuth client this installation registered (Manage Account → OAuth clients),
 * used by "Connect Cloudflare" to create tunnels and DNS without pasting tokens.
 */
class SettingsCloudflare extends Component
{
    public InstanceSettings $settings;

    public ?string $clientId = null;

    /** Write-only: never loaded from the database and cleared before every response. */
    public ?string $clientSecret = null;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $this->settings = instanceSettings();
        $this->clientId = $this->settings->cloudflare_oauth_client_id;
    }

    public function dehydrate(): void
    {
        $this->clientSecret = null;
    }

    public function save(): void
    {
        abort_unless(isInstanceAdmin(), 403);
        $hasStoredSecret = filled($this->settings->cloudflare_oauth_client_secret);

        $this->validate([
            'clientId' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'clientSecret' => [$hasStoredSecret ? 'nullable' : 'required', 'string', 'max:1024'],
        ], [
            'clientSecret.required' => 'Paste the client secret shown when you created the OAuth client.',
        ]);

        $this->settings->cloudflare_oauth_client_id = $this->clientId;
        if (filled($this->clientSecret)) {
            $this->settings->cloudflare_oauth_client_secret = $this->clientSecret;
        }
        $this->settings->save();

        auditLog('ui.instance.cloudflare_oauth.updated', ['secret_rotated' => filled($this->clientSecret)]);
        $this->dispatch('success', 'Cloudflare OAuth client saved.');
    }

    public function remove(): void
    {
        abort_unless(isInstanceAdmin(), 403);

        $this->settings->update(['cloudflare_oauth_client_id' => null, 'cloudflare_oauth_client_secret' => null]);
        $this->clientId = null;

        auditLog('ui.instance.cloudflare_oauth.removed', []);
        $this->dispatch('success', 'Cloudflare OAuth client removed.');
    }

    public function render(): Factory|View
    {
        return view('livewire.settings-cloudflare', [
            'callbackUrl' => url('/auth/cloudflare/callback'),
            'isConfigured' => filled($this->settings->cloudflare_oauth_client_id) && filled($this->settings->cloudflare_oauth_client_secret),
        ]);
    }
}
