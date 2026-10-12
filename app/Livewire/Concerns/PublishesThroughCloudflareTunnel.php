<?php

namespace App\Livewire\Concerns;

use App\Actions\Server\PublishCloudflareTunnelHostname;
use App\Models\CloudflareConnection;
use App\Models\Server;
use App\Services\CloudflareOAuth;
use Illuminate\Validation\Rule;

/**
 * Nimbus: "Publish through Cloudflare Tunnel" on the Domains pages. Adds <label>.<zone> as a domain through the
 * page's normal add flow, then routes it on the server's tunnel to Traefik.
 *
 * Using components implement cloudflareTunnelServer() and addCloudflareTunnelDomain().
 */
trait PublishesThroughCloudflareTunnel
{
    public string $cloudflareTunnelLabel = '';

    public string $cloudflareTunnelZone = '';

    public string $cloudflareTunnelScheme = 'https';

    public string $cloudflareTunnelReturnPath = '';

    abstract protected function cloudflareTunnelServer(): ?Server;

    /**
     * Adds the URL with the page's add-domain flow (which may also add its www. pair).
     *
     * @return list<string> URLs that were saved; empty when the flow refused or asked for confirmation
     */
    abstract protected function addCloudflareTunnelDomain(string $url): array;

    public function mountPublishesThroughCloudflareTunnel(): void
    {
        $this->cloudflareTunnelReturnPath = '/'.ltrim(request()->path(), '/');
    }

    public function cloudflareTunnelConnection(): ?CloudflareConnection
    {
        $teamId = $this->cloudflareTunnelServer()?->team_id;

        return $teamId === null ? null : once(fn () => CloudflareConnection::query()->where('team_id', $teamId)->first());
    }

    /** @return list<string> */
    public function cloudflareTunnelZones(): array
    {
        $connection = $this->cloudflareTunnelConnection();

        return $connection ? rescue(fn () => $connection->zoneNames(), [], report: false) : [];
    }

    public function cloudflareTunnelLoginAvailable(): bool
    {
        return app(CloudflareOAuth::class)->isConfigured();
    }

    public function publishThroughCloudflareTunnel(): void
    {
        $server = $this->cloudflareTunnelServer();
        $connection = $this->cloudflareTunnelConnection();
        if (! $server || ! $connection) {
            $this->dispatch('error', 'Connect Cloudflare first (Login with Cloudflare).');

            return;
        }

        $this->cloudflareTunnelLabel = strtolower(trim($this->cloudflareTunnelLabel));
        $this->validate([
            'cloudflareTunnelLabel' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'cloudflareTunnelZone' => ['required', Rule::in($this->cloudflareTunnelZones())],
            'cloudflareTunnelScheme' => ['required', Rule::in(['http', 'https'])],
        ], ['cloudflareTunnelLabel.regex' => 'Use lowercase letters, numbers and dashes, e.g. my-app.']);

        $hostname = "{$this->cloudflareTunnelLabel}.{$this->cloudflareTunnelZone}";
        $hosts = collect($this->addCloudflareTunnelDomain("{$this->cloudflareTunnelScheme}://{$hostname}"))
            ->map(fn (string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)))->filter()->unique();
        if ($hosts->isEmpty()) {
            return;
        }

        try {
            foreach ($hosts as $host) {
                // Traefik serves https:// domains with a certificate Cloudflare cannot verify (no public ACME
                // through the tunnel), so the tunnel skips verification and sends the hostname as SNI.
                $this->cloudflareTunnelScheme === 'https'
                    ? PublishCloudflareTunnelHostname::run($server, $host, $connection, 'https://localhost:443', ['noTLSVerify' => true, 'originServerName' => $host])
                    : PublishCloudflareTunnelHostname::run($server, $host, $connection, 'http://localhost:80');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', "{$hosts->implode(', ')} was added in Coolify, but publishing it on the Cloudflare Tunnel failed: {$e->getMessage()}");

            return;
        }

        auditLog('ui.domain.cloudflare_tunnel.published', ['server_id' => $server->id, 'hostnames' => $hosts->values()->all()]);
        $this->reset('cloudflareTunnelLabel', 'cloudflareTunnelZone');
        $this->dispatch('success', "{$hostname} is published through the Cloudflare Tunnel. Restart to apply.");
    }
}
