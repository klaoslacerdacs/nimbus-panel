<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Actions\Server\PublishCloudflareSshHostname;
use App\Actions\Server\UpdateCloudflared;
use App\Events\CloudflareTunnelChanged;
use App\Models\Server;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CloudflareTunnel extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Server $server;

    #[Validate(['required', 'string'])]
    public string $cloudflare_token;

    #[Validate(['required', 'string'])]
    public string $ssh_domain;

    #[Validate(['required', 'boolean'])]
    public bool $isCloudflareTunnelsEnabled;

    /** Nimbus "fix automatically": SSH hostname to publish on the tunnel. */
    public string $fixSshHostname = '';

    /** Used once for the Cloudflare API, never stored or sent back to the browser. */
    public ?string $cloudflareApiToken = null;

    public function dehydrate(): void
    {
        $this->cloudflareApiToken = null;
    }

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'CloudflareTunnelConfigured' => 'refresh',
        ]);
    }

    public function refresh()
    {
        $this->server->refresh();
        $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            if ($this->server->isLocalhost()) {
                return redirect()->route('server.show', ['server_uuid' => $server_uuid]);
            }
            $this->isCloudflareTunnelsEnabled = $this->server->settings->is_cloudflare_tunnel;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleCloudflareTunnels()
    {
        try {
            $this->authorize('update', $this->server);
            remote_process(['docker rm -f coolify-cloudflared'], $this->server, false, 10);
            $this->isCloudflareTunnelsEnabled = false;
            $this->server->settings->is_cloudflare_tunnel = false;
            $this->server->settings->save();
            auditLog('ui.server.cloudflare_tunnel.disabled', $this->auditContext());
            if ($this->server->ip_previous) {
                $this->server->update(['ip' => $this->server->ip_previous]);
                $this->dispatch('success', 'Cloudflare Tunnel disabled.<br><br>Manually updated the server IP address to its previous IP address.');
            } else {
                $this->dispatch('warning', 'Cloudflare Tunnel disabled. Action required: Update the server IP address to its real IP address in the Advanced settings.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function manualCloudflareConfig()
    {
        try {
            $this->authorize('update', $this->server);
            if (filter_var($this->server->ip, FILTER_VALIDATE_IP)) {
                $this->dispatch('error', 'Set the server address to the SSH hostname of the tunnel first.', 'Coolify connects through cloudflared access using that hostname; with an IP address every SSH connection would fail.');

                return;
            }
            $this->isCloudflareTunnelsEnabled = true;
            $this->server->settings->is_cloudflare_tunnel = true;
            $this->server->settings->save();
            auditLog('ui.server.cloudflare_tunnel.enabled', $this->auditContext());
            $this->server->refresh();
            $this->dispatch('success', 'Cloudflare Tunnel enabled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function automatedCloudflareConfig()
    {
        // Coolify connects with `cloudflared access ssh --hostname <ssh_domain>`: it must be the public hostname
        // routed to ssh://localhost:22 in the tunnel, never an IP or a bare name.
        $this->ssh_domain = trim(str_replace(['https://', 'http://', '/'], '', (string) $this->ssh_domain));
        $this->validate(
            ['ssh_domain' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i']],
            ['ssh_domain.regex' => 'Enter the full SSH hostname published in the tunnel (e.g. ssh-server.example.com), routed to ssh://localhost:22.'],
        );
        try {
            $this->authorize('update', $this->server);
            $activity = ConfigureCloudflared::run($this->server, $this->cloudflare_token, $this->ssh_domain);
            auditLog('ui.server.cloudflare_tunnel.configuration_started', $this->auditContext([
                'ssh_domain' => (string) $this->ssh_domain,
            ]));
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * One-click fix: publish the SSH hostname on the tunnel + DNS through the Cloudflare API, then let the
     * tunnel-finished check verify SSH through it before switching (it falls back to direct SSH on failure).
     */
    public function fixSshThroughTunnel()
    {
        $this->fixSshHostname = trim(str_replace(['https://', 'http://', '/'], '', strtolower($this->fixSshHostname)));
        $this->validate([
            'fixSshHostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'cloudflareApiToken' => ['required', 'string', 'max:255'],
        ], [
            'fixSshHostname.regex' => 'Enter a full hostname on a domain of your Cloudflare account, e.g. ssh-server.example.com.',
        ]);
        $apiToken = $this->cloudflareApiToken;

        try {
            $this->authorize('update', $this->server);
            PublishCloudflareSshHostname::run($this->server, $this->fixSshHostname, $apiToken);
            auditLog('ui.server.cloudflare_tunnel.ssh_hostname_published', $this->auditContext(['ssh_domain' => $this->fixSshHostname]));

            CloudflareTunnelChanged::dispatch(['server_id' => $this->server->id, 'ssh_domain' => $this->fixSshHostname]);
            $this->server->refresh();
            $this->isCloudflareTunnelsEnabled = (bool) $this->server->settings->is_cloudflare_tunnel;

            $this->isCloudflareTunnelsEnabled
                ? $this->dispatch('success', "SSH now goes through the tunnel at {$this->fixSshHostname}.")
                : $this->dispatch('error', 'The hostname was published, but SSH through it does not work yet. Coolify kept the direct address.', e((string) $this->server->validation_logs));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updateCloudflareTunnel()
    {
        try {
            $this->authorize('update', $this->server);
            $activity = UpdateCloudflared::run($this->server);
            auditLog('ui.server.cloudflare_tunnel.update_started', $this->auditContext());
            $this->dispatch('cloudflare-tunnel-update-started');
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.cloudflare-tunnel');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function auditContext(array $context = []): array
    {
        return array_merge([
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
        ], $context);
    }
}
