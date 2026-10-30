<?php

namespace App\Services;

use App\Actions\Server\ConfigureCloudflared;
use App\Enums\ManagedDnsDeletionResult;
use App\Exceptions\DnsRecordConflictException;
use App\Models\DnsProviderZone;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\Server;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Sets up and removes a remotely managed Cloudflare Tunnel for server SSH with a stored Cloudflare API token.
 * The tunnel token is never stored: Cloudflare returns it on every setup run.
 */
class CloudflareTunnelService
{
    private const API_URL = 'https://api.cloudflare.com/client/v4';

    private const TUNNEL_PERMISSION = 'Account → Cloudflare Tunnel → Edit';

    /** Cloudflare does not delete a tunnel while the stopping connector is still connected; wait at most this often. */
    private const DELETE_ATTEMPTS = 5;

    public function __construct(
        private CloudflareDnsProvider $dns,
        private ManagedDnsRecordCleanup $cleanup,
    ) {}

    public static function tunnelName(Server $server): string
    {
        return "coolify-{$server->uuid}";
    }

    /**
     * Creates (or reuses) the tunnel, routes the hostname to the server SSH port, points a proxied CNAME at the tunnel,
     * and starts cloudflared. The CloudflareTunnelChanged listener enables the tunnel when cloudflared is healthy.
     *
     * @param  array{record_id: string, current: string}|null  $confirmedConflict  An existing DNS record the user agreed to replace.
     *
     * @throws DnsRecordConflictException when another DNS record already uses the hostname
     * @throws LockTimeoutException when another DNS change for the hostname does not finish in time
     * @throws RuntimeException when a Cloudflare call fails
     */
    public function setUp(Server $server, IntegrationToken $token, string $hostname, ?array $confirmedConflict = null, ?string $existingTunnelId = null): Activity
    {
        $server->assertNotTransferredAway();
        $zone = $this->zoneFor($server, $token, $hostname);
        $accountId = $zone->account_id;
        if (blank($accountId)) {
            throw new RuntimeException("Cloudflare returned no account for the zone {$zone->name}.");
        }

        $sshRule = ['hostname' => $hostname, 'service' => "ssh://localhost:{$server->port}"];
        if ($existingTunnelId !== null) {
            // Nimbus: a tunnel the user already has keeps its other routes; only the SSH hostname is added.
            $tunnelId = $existingTunnelId;
            $created = false;
            $tunnelToken = $this->tunnelToken($token, $accountId, $tunnelId);
            $current = $this->request($token, 'get', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations")->json('result.config.ingress');
            $ingress = self::ingressWith(is_array($current) ? $current : [], $sshRule);
        } else {
            [$tunnelId, $tunnelToken, $created] = $this->findOrCreateTunnel($token, $accountId, self::tunnelName($server));
            $ingress = [$sshRule, ['service' => 'http_status:404']];
        }

        try {
            $this->request($token, 'put', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", [
                'config' => ['ingress' => $ingress],
            ]);

            $target = "{$tunnelId}.cfargotunnel.com";
            if ($confirmedConflict !== null) {
                $this->dns->replaceRecord($zone, $confirmedConflict['record_id'], $hostname, $target, $server, $confirmedConflict['current'], proxied: true);
            } else {
                $this->dns->createRecord($zone, $hostname, $target, $server, proxied: true);
            }
        } catch (Throwable $e) {
            // Coolify does not track the tunnel yet, so a tunnel created by this run would stay in Cloudflare.
            if ($created) {
                $this->deleteUntrackedTunnel($token, $accountId, $tunnelId);
            }

            throw $e;
        }
        $this->releaseDnsRecords($server, except: $hostname);

        // Saved before cloudflared starts, so disabling the tunnel can clean up even when cloudflared does not become healthy.
        $server->settings->update([
            'cloudflare_tunnel_id' => $tunnelId,
            'cloudflare_tunnel_account_id' => $accountId,
            'cloudflare_tunnel_integration_token_id' => $token->id,
        ]);

        return ConfigureCloudflared::run($server, $tunnelToken, $hostname);
    }

    /**
     * Tunnels of the accounts the token can see, for the "use an existing tunnel" choice. A tunnel whose connectors
     * run on another machine is flagged: Cloudflare balances every route over all connectors, so joining it from
     * this server would send the other hostnames here too.
     *
     * @return list<array{id: string, name: string, account_id: string, account_name: string|null, active_elsewhere: bool}>
     */
    public function listTunnels(IntegrationToken $token, ?string $serverIp = null): array
    {
        $accounts = fn () => $token->dnsZones()->whereNotNull('account_id')->get()->unique('account_id');
        $zones = $accounts();
        if ($zones->isEmpty()) {
            $this->dns->syncZones($token);
            $zones = $accounts();
        }

        return $zones->flatMap(fn (DnsProviderZone $zone) => collect($this->request($token, 'get', "/accounts/{$zone->account_id}/cfd_tunnel", ['is_deleted' => 'false', 'per_page' => 1000])->json('result', []))
            ->filter(fn ($tunnel): bool => is_array($tunnel) && is_string($tunnel['id'] ?? null))
            ->map(fn (array $tunnel): array => [
                'id' => $tunnel['id'],
                'name' => (string) ($tunnel['name'] ?? $tunnel['id']),
                'account_id' => (string) $zone->account_id,
                'account_name' => $zone->account_name,
                'active_elsewhere' => in_array($tunnel['status'] ?? null, ['healthy', 'degraded'], true)
                    && ! collect($tunnel['connections'] ?? [])->contains(fn ($connection): bool => is_array($connection) && ($connection['origin_ip'] ?? null) === $serverIp),
            ]))->sortBy('name')->values()->all();
    }

    /**
     * The ingress of an existing tunnel with the SSH rule first: a previous rule for the same hostname is replaced
     * and the catch-all rule stays last (Cloudflare requires one).
     *
     * @param  list<array<string, mixed>>  $current
     * @param  array{hostname: string, service: string}  $sshRule
     * @return list<array<string, mixed>>
     */
    public static function ingressWith(array $current, array $sshRule): array
    {
        $rules = array_values(array_filter($current, 'is_array'));
        // Only the last hostname-less rule is the catch-all; path-only rules in the middle are kept.
        $catchAll = $rules !== [] && blank(end($rules)['hostname'] ?? null) ? array_pop($rules) : ['service' => 'http_status:404'];
        $others = array_filter($rules, fn (array $rule): bool => ($rule['hostname'] ?? null) !== $sshRule['hostname']);

        return [$sshRule, ...array_values($others), $catchAll];
    }

    /**
     * The ingress without the given hostnames (the catch-all stays), for leaving a tunnel the user owns.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<string>  $hostnames
     * @return list<array<string, mixed>>
     */
    public static function ingressWithout(array $current, array $hostnames): array
    {
        return array_values(array_filter($current, fn ($rule): bool => is_array($rule) && ! in_array($rule['hostname'] ?? null, $hostnames, true)));
    }

    /**
     * Stops cloudflared and deletes the tunnel and the DNS record that setUp() created.
     * Never throws; returns warnings for the user and always forgets the tunnel.
     *
     * @return array<int, string>
     */
    public function tearDown(Server $server): array
    {
        $settings = $server->settings;
        if (blank($settings->cloudflare_tunnel_id)) {
            return [];
        }
        // Another Coolify instance uses the tunnel of a transferred server.
        if ($server->isTransferredAway()) {
            return ['This server is managed by another Coolify instance.'];
        }

        $warnings = [];
        $name = self::tunnelName($server);
        $token = IntegrationToken::query()
            ->where('team_id', $server->team_id)
            ->whereKey($settings->cloudflare_tunnel_integration_token_id)
            ->first();

        // Coolify usually reaches the server through this tunnel. Stopping cloudflared in the SSH session would cut
        // that session and make SSH retry, so the stop runs detached and the session ends first.
        instant_remote_process([
            "nohup setsid sh -c 'sleep 2; docker rm -f coolify-cloudflared' > /dev/null 2>&1 < /dev/null &",
        ], $server, false);

        // The DNS record does not depend on the connector, so it goes first while cloudflared stops.
        $hostnames = $this->serverDnsRecords($server)->pluck('name')->all();
        array_push($warnings, ...$this->releaseDnsRecords($server));

        if ($token === null) {
            $warnings[] = "The Cloudflare token of the tunnel no longer exists. Delete the tunnel {$name} in Cloudflare.";
        } elseif (($warning = $this->releaseTunnel($token, $settings->cloudflare_tunnel_account_id, $settings->cloudflare_tunnel_id, $name, $hostnames)) !== null) {
            $warnings[] = $warning;
        }

        $settings->update([
            'cloudflare_tunnel_id' => null,
            'cloudflare_tunnel_account_id' => null,
            'cloudflare_tunnel_integration_token_id' => null,
        ]);

        return $warnings;
    }

    /**
     * A tunnel Coolify created is deleted; a tunnel the user chose only loses the SSH routes Coolify added.
     *
     * @param  list<string>  $hostnames
     */
    private function releaseTunnel(IntegrationToken $token, string $accountId, string $tunnelId, string $name, array $hostnames): ?string
    {
        $path = self::API_URL."/accounts/{$accountId}/cfd_tunnel/{$tunnelId}";

        try {
            $tunnel = $this->client($token)->get($path);
            if ($tunnel->status() === 404 || $tunnel->json('result.name') === $name) {
                return $this->deleteStoppedTunnel($token, $accountId, $tunnelId, $name);
            }
            if (! $tunnel->successful()) {
                // Unknown owner: never delete blindly.
                return "Cloudflare did not answer about the tunnel {$name}. Check it in Cloudflare.";
            }
            $ownName = (string) ($tunnel->json('result.name') ?? $tunnelId);
            $current = $this->client($token)->get("{$path}/configurations")->json('result.config.ingress');
            $response = $this->client($token)->put("{$path}/configurations", [
                'config' => ['ingress' => self::ingressWithout(is_array($current) ? $current : [], $hostnames)],
            ]);
            if ($response->successful() && $response->json('success') !== false) {
                return null;
            }
        } catch (Throwable) {
            // Reported below.
        }

        return 'Remove '.implode(', ', $hostnames ?: ['the SSH hostname'])." from the tunnel {$ownName} in Cloudflare.";
    }

    /**
     * Deletes the tunnel once its connector is gone. Removing the connections first lets Cloudflare forget a connector
     * that already stopped; a connector that is still stopping keeps the delete failing until it disconnects.
     */
    private function deleteStoppedTunnel(IntegrationToken $token, string $accountId, string $tunnelId, string $name): ?string
    {
        $path = self::API_URL."/accounts/{$accountId}/cfd_tunnel/{$tunnelId}";

        try {
            for ($attempt = 1; $attempt <= self::DELETE_ATTEMPTS; $attempt++) {
                $this->client($token)->delete("{$path}/connections");
                $response = $this->client($token)->delete($path);
                if ($response->successful() || $response->status() === 404) {
                    return null;
                }
                if ($response->status() === 403) {
                    return CloudflareDnsProvider::missingPermissionMessage(self::TUNNEL_PERMISSION)." Delete the tunnel {$name} in Cloudflare.";
                }
                if ($attempt < self::DELETE_ATTEMPTS) {
                    Sleep::for(2)->seconds();
                }
            }
        } catch (Throwable) {
            return "Cloudflare did not respond. Delete the tunnel {$name} in Cloudflare.";
        }

        return "Cloudflare could not delete the tunnel {$name}. Delete it in Cloudflare.";
    }

    private function zoneFor(Server $server, IntegrationToken $token, string $hostname): DnsProviderZone
    {
        $find = fn (): ?DnsProviderZone => $this->dns->findZones($server->team_id, $hostname)
            ->firstWhere('integration_token_id', $token->id);

        $zone = $find();
        if ($zone === null) {
            $this->dns->syncZones($token);
            $zone = $find();
        }

        return $zone ?? throw new RuntimeException("No Cloudflare zone of the token {$token->name} manages {$hostname}.");
    }

    /**
     * @return array{0: string, 1: string, 2: bool} tunnel ID, tunnel token, and whether this call created the tunnel
     */
    private function findOrCreateTunnel(IntegrationToken $token, string $accountId, string $name): array
    {
        $existing = collect($this->request($token, 'get', "/accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'is_deleted' => 'false',
        ])->json('result', []))->first(fn ($tunnel): bool => is_array($tunnel) && ($tunnel['name'] ?? null) === $name);

        if (is_string($existing['id'] ?? null)) {
            return [$existing['id'], $this->tunnelToken($token, $accountId, $existing['id']), false];
        }

        $response = $this->request($token, 'post', "/accounts/{$accountId}/cfd_tunnel", [
            'name' => $name,
            'config_src' => 'cloudflare',
        ]);
        $tunnelId = $response->json('result.id');
        $tunnelToken = $response->json('result.token');
        if (! is_string($tunnelId) || ! is_string($tunnelToken) || $tunnelToken === '') {
            throw new RuntimeException('Cloudflare returned no tunnel token.');
        }

        return [$tunnelId, $tunnelToken, true];
    }

    private function tunnelToken(IntegrationToken $token, string $accountId, string $tunnelId): string
    {
        $tunnelToken = $this->request($token, 'get', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token")->json('result');
        if (! is_string($tunnelToken) || $tunnelToken === '') {
            throw new RuntimeException('Cloudflare returned no tunnel token.');
        }

        return $tunnelToken;
    }

    /**
     * Best effort: the tunnel has no connector yet, so Cloudflare deletes it directly.
     */
    private function deleteUntrackedTunnel(IntegrationToken $token, string $accountId, string $tunnelId): void
    {
        try {
            $this->client($token)->delete(self::API_URL."/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        } catch (Throwable) {
            // The original error matters more; a re-run reuses the tunnel by name anyway.
        }
    }

    /**
     * Releases the server's managed DNS records (except the given hostname). Records are deleted only when
     * Coolify created them and nothing else uses the hostname.
     *
     * @return array<int, string>
     */
    private function releaseDnsRecords(Server $server, ?string $except = null): array
    {
        $key = [$server->getMorphClass(), $server->getKey()];

        return $this->serverDnsRecords($server, $except)->map(function (ManagedDnsRecord $record) use ($key): ?string {
            try {
                $result = $this->cleanup->release($record, [$key], true);
            } catch (Throwable) {
                $result = ManagedDnsDeletionResult::Failed;
            }

            return match ($result) {
                ManagedDnsDeletionResult::ChangedExternally => "The DNS record {$record->name} changed outside Coolify and was left untouched.",
                ManagedDnsDeletionResult::Failed, ManagedDnsDeletionResult::Busy => "The DNS record {$record->name} could not be deleted. Delete it in Cloudflare.",
                default => null,
            };
        })->filter()->values()->all();
    }

    /**
     * The managed DNS records that point at this server (except the given hostname).
     *
     * @return Collection<int, ManagedDnsRecord>
     */
    private function serverDnsRecords(Server $server, ?string $except = null): Collection
    {
        return ManagedDnsRecord::query()
            ->where('team_id', $server->team_id)
            ->when($except !== null, fn ($query) => $query->where('name', '!=', $except))
            ->whereHas('references', fn ($query) => $query->where('resource_type', $server->getMorphClass())->where('resource_id', $server->getKey()))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(IntegrationToken $token, string $method, string $path, array $data = []): Response
    {
        $response = $this->client($token)->{$method}(self::API_URL.$path, $data);

        if ($response->status() === 403) {
            throw new RuntimeException(CloudflareDnsProvider::missingPermissionMessage(self::TUNNEL_PERMISSION));
        }
        if (! $response->successful() || $response->json('success') === false) {
            $message = $response->json('errors.0.message');

            throw new RuntimeException('Cloudflare Tunnel request failed'.(is_string($message) ? ": {$message}" : '.'));
        }

        return $response;
    }

    private function client(IntegrationToken $token): PendingRequest
    {
        return Http::withToken($token->token)->acceptJson();
    }
}
