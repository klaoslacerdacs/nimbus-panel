<?php

namespace App\Livewire\Server;

use App\Actions\Server\ConfigureCloudflared;
use App\Actions\Server\PublishCloudflareTunnelHostname;
use App\Actions\Server\UpdateCloudflared;
use App\Events\CloudflareTunnelChanged;
use App\Exceptions\DnsRecordConflictException;
use App\Models\CloudflareConnection;
use App\Models\IntegrationToken;
use App\Models\Server;
use App\Rules\ValidHostname;
use App\Services\CloudflareOAuth;
use App\Services\CloudflareTunnelService;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    /** Label and zone used instead of fixSshHostname when the team's Cloudflare account is connected. */
    public string $fixSshLabel = '';

    public string $fixSshZone = '';

    /** Used once for the Cloudflare API, never stored or sent back to the browser. */
    public ?string $cloudflareApiToken = null;

    public function dehydrate(): void
    {
        $this->cloudflareApiToken = null;
    }

    public ?int $cloudflare_integration_token_id = null;

    public string $api_ssh_domain = '';

    /**
     * An existing DNS record on the hostname that blocks the tunnel CNAME, waiting for the user to confirm its replacement.
     *
     * @var array{record_id: string, current: string, hostname: string, token_id: int}|null
     */
    #[Locked]
    public ?array $dnsConflict = null;

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'CloudflareTunnelConfigured' => 'refresh',
        ]);
    }

    /**
     * Selects the token that the user just added from this page, when it is the only one that can manage tunnels.
     */
    #[On('integrationTokenAdded')]
    public function selectAddedToken(): void
    {
        $tunnelTokens = $this->tunnelTokens();
        if ($this->cloudflare_integration_token_id === null && $tunnelTokens->count() === 1) {
            $this->cloudflare_integration_token_id = $tunnelTokens->first()->id;
        }
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
            $this->suggestSshHostname();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * With a connected account the hostname is split into a label (ssh-<server name>) and a zone picked from the
     * account; the zone of the panel's own domain is preselected when the account has it.
     */
    private function suggestSshHostname(): void
    {
        $zones = $this->cloudflareZones();
        if ($zones === []) {
            return;
        }

        $this->fixSshLabel = 'ssh-'.str($this->server->name)->slug()->limit(55, '')->trim('-');
        $panelHost = (string) parse_url((string) (instanceSettings()->fqdn ?: config('app.url')), PHP_URL_HOST);
        $this->fixSshZone = collect($zones)->first(fn (string $zone): bool => $panelHost === $zone || str_ends_with($panelHost, ".{$zone}")) ?? '';
    }

    /** @return list<string> */
    private function cloudflareZones(): array
    {
        return once(fn (): array => $this->cloudflareConnection() ? rescue(fn () => $this->cloudflareConnection()->zoneNames(), [], report: false) : []);
    }

    public function toggleCloudflareTunnels(CloudflareTunnelService $tunnels)
    {
        try {
            $this->authorize('update', $this->server);
            $this->server->assertNotTransferredAway();
            if (filled($this->server->settings->cloudflare_tunnel_id)) {
                foreach ($tunnels->tearDown($this->server) as $warning) {
                    $this->dispatch('warning', $warning);
                }
            } else {
                remote_process(['docker rm -f coolify-cloudflared'], $this->server, false, 10);
            }
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
            $this->server->assertNotTransferredAway();
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
            $this->server->assertNotTransferredAway();
            $this->ssh_domain = $this->normalizeSshDomain($this->ssh_domain);
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
        if ($this->cloudflareZones() !== []) {
            $this->validate([
                'fixSshLabel' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
                'fixSshZone' => ['required', Rule::in($this->cloudflareZones())],
            ], [
                'fixSshLabel.regex' => 'Use lowercase letters, numbers and dashes, e.g. ssh-my-server.',
            ]);
            $this->fixSshHostname = "{$this->fixSshLabel}.{$this->fixSshZone}";
        }
        $this->fixSshHostname = trim(str_replace(['https://', 'http://', '/'], '', strtolower($this->fixSshHostname)));
        $this->validate([
            'fixSshHostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'cloudflareApiToken' => [$this->cloudflareConnection() ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'fixSshHostname.regex' => 'Enter a full hostname on a domain of your Cloudflare account, e.g. ssh-server.example.com.',
        ]);
        // A pasted token wins, so a tunnel in another account can still be fixed while the team is connected.
        $credential = filled($this->cloudflareApiToken) ? $this->cloudflareApiToken : $this->cloudflareConnection();

        try {
            $this->authorize('update', $this->server);
            $this->server->assertNotTransferredAway();
            PublishCloudflareTunnelHostname::run($this->server, $this->fixSshHostname, $credential);
            auditLog('ui.server.cloudflare_tunnel.ssh_hostname_published', $this->auditContext(['ssh_domain' => $this->fixSshHostname]));

            CloudflareTunnelChanged::dispatch(['server_id' => $this->server->id, 'team_id' => $this->server->team_id, 'ssh_domain' => $this->fixSshHostname]);
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

    /** The Cloudflare account connected by the server's team (Security → Cloudflare). */
    private function cloudflareConnection(): ?CloudflareConnection
    {
        return once(fn () => CloudflareConnection::query()->where('team_id', $this->server->team_id)->first());
    }

    public function apiCloudflareConfig(CloudflareTunnelService $tunnels): void
    {
        $this->authorize('update', $this->server);
        $this->api_ssh_domain = $this->normalizeSshDomain($this->api_ssh_domain);
        $this->validate([
            'cloudflare_integration_token_id' => ['required', 'integer'],
            'api_ssh_domain' => ['required', 'string', new ValidHostname],
        ], [], ['cloudflare_integration_token_id' => 'Cloudflare token', 'api_ssh_domain' => 'SSH hostname']);

        $token = $this->tunnelTokens()->firstWhere('id', $this->cloudflare_integration_token_id);
        if ($token === null) {
            $this->addError('cloudflare_integration_token_id', 'Select a Cloudflare token of this server team with the Cloudflare Tunnel capability.');

            return;
        }

        $this->dnsConflict = null;
        $this->startApiSetup($tunnels, $token, $this->api_ssh_domain);
    }

    public function replaceDnsAndConfigure(CloudflareTunnelService $tunnels): void
    {
        $this->authorize('update', $this->server);
        $conflict = $this->dnsConflict;
        $token = $conflict === null ? null : $this->tunnelTokens()->firstWhere('id', $conflict['token_id']);
        if ($conflict === null || $token === null) {
            $this->dnsConflict = null;
            $this->dispatch('error', 'The DNS conflict is no longer available. Set up the tunnel again.');

            return;
        }

        $this->dnsConflict = null;
        $this->startApiSetup($tunnels, $token, $conflict['hostname'], $conflict);
    }

    public function render()
    {
        $cloudflareTokens = IntegrationToken::query()
            ->where('team_id', $this->server->team_id)
            ->where('provider', 'cloudflare')
            ->count();

        return view('livewire.server.cloudflare-tunnel', [
            'tunnelTokens' => $this->tunnelTokens(),
            'hasCloudflareTokens' => $cloudflareTokens > 0,
            'cloudflareConnection' => $this->cloudflareConnection(),
            'cloudflareOAuthConfigured' => app(CloudflareOAuth::class)->isConfigured(),
            'cloudflareZones' => $this->cloudflareZones(),
        ]);
    }

    /**
     * Cloudflare tokens of the server's team (not the session team) that can manage tunnels.
     *
     * @return Collection<int, IntegrationToken>
     */
    private function tunnelTokens(): Collection
    {
        return IntegrationToken::query()
            ->where('team_id', $this->server->team_id)
            ->where('provider', 'cloudflare')
            ->orderBy('name')
            ->get()
            ->filter(fn (IntegrationToken $token): bool => $token->hasCapability('tunnel'))
            ->values();
    }

    /**
     * @param  array{record_id: string, current: string}|null  $confirmedConflict
     */
    private function startApiSetup(CloudflareTunnelService $tunnels, IntegrationToken $token, string $hostname, ?array $confirmedConflict = null): void
    {
        try {
            $activity = $tunnels->setUp($this->server, $token, $hostname, $confirmedConflict);
            auditLog('ui.server.cloudflare_tunnel.api_configuration_started', $this->auditContext([
                'ssh_domain' => $hostname,
                'integration_token_uuid' => $token->uuid,
            ]));
            $this->dispatch('automated');
            $this->dispatch('activityMonitor', $activity->id);
        } catch (DnsRecordConflictException $e) {
            $this->dnsConflict = [
                'record_id' => $e->providerRecordId,
                'current' => $e->currentValue,
                'hostname' => $hostname,
                'token_id' => $token->id,
            ];
        } catch (LockTimeoutException) {
            $this->dispatch('error', "Another DNS change for {$hostname} is in progress. Try again in a few seconds.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function normalizeSshDomain(string $domain): string
    {
        return str($domain)->replace(['https://', 'http://'], '')->replace('/', '')->trim()->lower()->value();
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
