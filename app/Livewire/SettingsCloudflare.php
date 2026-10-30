<?php

namespace App\Livewire;

use App\Models\CloudflareConnection;
use App\Models\InstanceSettings;
use App\Services\CloudflareOAuth;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

/**
 * Nimbus: the Cloudflare OAuth client this installation registered (Manage Account → OAuth clients),
 * used by "Connect Cloudflare" to create tunnels and DNS without pasting tokens.
 */
class SettingsCloudflare extends Component
{
    public InstanceSettings $settings;

    public ?string $clientId = null;

    public ?string $scopes = null;

    /** Write-only: never loaded from the database and cleared before every response. */
    public ?string $clientSecret = null;

    public ?string $flashError = null;

    public ?string $flashSuccess = null;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $this->flashError = session('error');
        $this->flashSuccess = session('success');

        $this->settings = instanceSettings();
        $this->clientId = $this->settings->cloudflare_oauth_client_id;
        $this->scopes = $this->settings->cloudflare_oauth_scopes;
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
            'clientId' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', 'not_regex:/^cfoc_/'],
            'scopes' => ['nullable', 'string', 'max:1000', 'regex:/^[A-Za-z0-9._:\-\s,]+$/'],
            'clientSecret' => [$hasStoredSecret ? 'nullable' : 'required', 'string', 'max:1024'],
        ], [
            'clientSecret.required' => 'Paste the client secret shown when you created the OAuth client.',
            'clientId.not_regex' => 'This is the client secret (it starts with cfoc_). Paste it in Client secret, and the Client ID here.',
        ]);

        $this->settings->cloudflare_oauth_client_id = $this->clientId;
        $this->settings->cloudflare_oauth_scopes = filled($this->scopes) ? trim($this->scopes) : null;
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

        $this->settings->update(['cloudflare_oauth_client_id' => null, 'cloudflare_oauth_client_secret' => null, 'cloudflare_oauth_scopes' => null]);
        $this->clientId = null;

        auditLog('ui.instance.cloudflare_oauth.removed', []);
        $this->dispatch('success', 'Cloudflare OAuth client removed.');
    }

    /**
     * Checks the Cloudflare login of the session team; when it is gone or cannot be refreshed, starts the
     * OAuth login again and returns here.
     */
    public function reconnect(): mixed
    {
        abort_unless(isInstanceAdmin() && auth()->user()->isAdminOfTeam(currentTeam()->id), 403);
        if (! app(CloudflareOAuth::class)->isConfigured()) {
            $this->dispatch('error', 'Save the OAuth client first.');

            return null;
        }

        $connection = $this->connection();
        if ($connection && ! $connection->needsReconnect()) {
            try {
                $connection->api()->get("/accounts/{$connection->account_id}")->throw();
                $this->dispatch('success', "Cloudflare login OK: {$connection->account_name}.");

                return null;
            } catch (RequestException $e) {
                if (! in_array($e->response->status(), [401, 403], true)) {
                    $this->dispatch('error', 'Cloudflare did not answer: HTTP '.$e->response->status().'. Try again later.');

                    return null;
                }
                // The grant was revoked: log in again.
            } catch (\Throwable $e) {
                $this->dispatch('error', 'Cloudflare did not answer: '.$e->getMessage());

                return null;
            }
        }

        return redirect()->route('cloudflare.connect', ['return' => route('settings.cloudflare', absolute: false)]);
    }

    private function connection(): ?CloudflareConnection
    {
        return CloudflareConnection::query()->where('team_id', currentTeam()->id)->first();
    }

    public function render(): Factory|View
    {
        return view('livewire.settings-cloudflare', [
            'connection' => $this->connection(),
            'teamName' => currentTeam()->name,
            'canReconnect' => auth()->user()->isAdminOfTeam(currentTeam()->id),
            'callbackUrl' => url('/auth/cloudflare/callback'),
            'isConfigured' => app(CloudflareOAuth::class)->isConfigured(),
            'defaultScopes' => CloudflareOAuth::DEFAULT_SCOPES,
        ]);
    }
}
