<?php

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use SensitiveParameter;

/**
 * Publishes `hostname → ssh://localhost:22` on the server's Cloudflare Tunnel and points the hostname's DNS at
 * the tunnel, so Coolify can SSH through `cloudflared access ssh`. The API token is used once and never stored.
 */
class PublishCloudflareSshHostname
{
    use AsAction;

    private const string API = 'https://api.cloudflare.com/client/v4';

    public function handle(Server $server, string $hostname, #[SensitiveParameter] string $apiToken): void
    {
        ['a' => $accountId, 't' => $tunnelId] = $this->tunnelIdentity($server);
        $api = Http::withToken($apiToken)->acceptJson()->timeout(20)->baseUrl(self::API);

        $config = $this->call($api->get("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations"))['config'] ?? null;
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
        $this->call($api->put("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", ['config' => $config]));

        $zoneId = $this->zoneFor($api, $hostname);
        $record = ['type' => 'CNAME', 'name' => $hostname, 'content' => "{$tunnelId}.cfargotunnel.com", 'proxied' => true];
        $existing = $this->call($api->get("/zones/{$zoneId}/dns_records", ['name' => $hostname]))[0]['id'] ?? null;
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
        $line = (string) instant_remote_process(['grep -m1 TUNNEL_TOKEN= '.ConfigureCloudflared::DIRECTORY.'/docker-compose.yml'], $server);
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
    private function call(Response $response): array
    {
        $body = $response->json();
        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $message = collect($body['errors'] ?? [])->pluck('message')->implode('; ') ?: 'HTTP '.$response->status();

            throw new RuntimeException("Cloudflare API: {$message}");
        }

        return $body['result'] ?? [];
    }
}
