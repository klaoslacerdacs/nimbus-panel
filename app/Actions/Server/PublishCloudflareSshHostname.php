<?php

namespace App\Actions\Server;

use App\Models\CloudflareConnection;
use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use SensitiveParameter;

/**
 * Publishes `hostname → ssh://localhost:22` on the server's Cloudflare Tunnel and points the hostname's DNS at
 * the tunnel, so Coolify can SSH through `cloudflared access ssh`. Uses the team's OAuth connection, or a pasted API
 * token that is used once and never stored.
 */
class PublishCloudflareSshHostname
{
    use AsAction;

    private const string API = 'https://api.cloudflare.com/client/v4';

    public function handle(Server $server, string $hostname, #[SensitiveParameter] CloudflareConnection|string $credential): void
    {
        ['a' => $accountId, 't' => $tunnelId] = $this->tunnelIdentity($server);

        if ($credential instanceof CloudflareConnection && $credential->account_id !== $accountId) {
            throw new RuntimeException("This server's tunnel belongs to another Cloudflare account than the one connected ({$credential->account_name}). Paste an API token of the tunnel's account instead.");
        }
        $api = $credential instanceof CloudflareConnection
            ? $credential->api()
            : Http::withToken($credential)->acceptJson()->timeout(20)->baseUrl(self::API);

        $current = $this->call($api->get("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations"), 'Cloudflare Tunnel Write');
        // A dashboard-managed tunnel that never had a hostname comes back with config: null (version 0).
        $config = $current['config'] ?? (($current['source'] ?? 'cloudflare') === 'cloudflare' ? ['ingress' => []] : null);
        if (! is_array($config)) {
            throw new RuntimeException('This tunnel is configured by a local file, not in the Cloudflare dashboard. Add the SSH hostname to that file instead.');
        }

        // Keep every existing route; replace an older rule for the same hostname; the catch-all must stay last.
        $routes = collect($config['ingress'] ?? [])->reject(fn (array $rule): bool => ($rule['hostname'] ?? null) === $hostname);
        $catchAll = $routes->first(fn (array $rule): bool => ! isset($rule['hostname'])) ?? ['service' => 'http_status:404'];
        $config['ingress'] = [
            ...$routes->filter(fn (array $rule): bool => isset($rule['hostname']))->values()->all(),
            ['hostname' => $hostname, 'service' => 'ssh://localhost:22'],
            $catchAll,
        ];
        $this->call($api->put("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", ['config' => $config]), 'Cloudflare Tunnel Write');

        $zoneId = $this->zoneFor($api, $hostname);
        $record = ['type' => 'CNAME', 'name' => $hostname, 'content' => "{$tunnelId}.cfargotunnel.com", 'proxied' => true];
        $records = collect($this->call($api->get("/zones/{$zoneId}/dns_records", ['name' => $hostname])));
        if ($other = $records->firstWhere(fn (array $record): bool => $record['type'] !== 'CNAME')) {
            throw new RuntimeException("{$hostname} already has a {$other['type']} record in Cloudflare. Choose another hostname or remove that record.");
        }
        $existing = $records->first()['id'] ?? null;
        $existing
            ? $this->call($api->put("/zones/{$zoneId}/dns_records/{$existing}", $record))
            : $this->call($api->post("/zones/{$zoneId}/dns_records", $record));
    }

    /**
     * Account and tunnel ids from the tunnel token Coolify wrote on the server (base64 JSON {a, t, s}).
     *
     * @return array{a: string, t: string}
     */
    private function tunnelIdentity(Server $server): array
    {
        $directory = app(UpdateCloudflared::class)->findConfigurationDirectory($server);
        $line = (string) instant_remote_process(["grep -m1 TUNNEL_TOKEN= {$directory}/docker-compose.yml || true"], $server);
        $token = trim((string) str($line)->after('TUNNEL_TOKEN=')->trim(" \t'\"-"));
        $identity = json_decode((string) base64_decode($token, true), true);

        if (! is_array($identity) || empty($identity['a']) || empty($identity['t'])) {
            throw new RuntimeException('The tunnel on this server was not set up by the automated setup. Run the automated setup first.');
        }

        return ['a' => (string) $identity['a'], 't' => (string) $identity['t']];
    }

    private function zoneFor(PendingRequest $api, string $hostname): string
    {
        $labels = explode('.', $hostname);
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            $zone = $this->call($api->get('/zones', ['name' => implode('.', array_slice($labels, $i))]))[0]['id'] ?? null;
            if ($zone) {
                return $zone;
            }
        }

        throw new RuntimeException("No Cloudflare zone found for {$hostname}. The domain must be on this Cloudflare account.");
    }

    /**
     * @return array<mixed>
     */
    private function call(Response $response, string $permission = 'DNS Write and Zone Read'): array
    {
        $body = $response->json();
        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $message = collect($body['errors'] ?? [])->pluck('message')->implode('; ') ?: 'HTTP '.$response->status();
            if (in_array($response->status(), [401, 403], true)) {
                $message .= ". The Cloudflare login or token needs the {$permission} permission; add it to the OAuth client (or token) and reconnect";
            }

            throw new RuntimeException("Cloudflare API: {$message}");
        }

        return $body['result'] ?? [];
    }
}
