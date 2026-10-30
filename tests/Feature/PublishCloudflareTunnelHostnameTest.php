<?php

use App\Actions\Server\PublishCloudflareTunnelHostname;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id]);

    // The server runs cloudflared with a tunnel token of account acc123 / tunnel tun456.
    $tunnelToken = base64_encode(json_encode(['a' => 'acc123', 't' => 'tun456', 's' => 'secret']));
    Process::fake(fn ($process) => match (true) {
        str_contains($process->command, 'for dir in') => Process::result(output: '/data/coolify/cloudflared'),
        str_contains($process->command, 'TUNNEL_TOKEN') => Process::result(output: "      - TUNNEL_TOKEN={$tunnelToken}"),
        default => Process::result(output: ''),
    });
});

function publishHostnameHttpFake(array $overrides = []): void
{
    Http::fake($overrides + [
        'api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel/tun456/configurations' => Http::sequence()
            ->push(['success' => true, 'result' => ['config' => ['ingress' => [
                ['hostname' => 'app.example.com', 'service' => 'http://localhost:80'],
                ['service' => 'http_status:404'],
            ]]]])
            ->push(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/zones?name=example.com' => ['success' => true, 'result' => [['id' => 'zone789']]],
        'api.cloudflare.com/client/v4/zones/zone789/dns_records?*' => ['success' => true, 'result' => []],
        'api.cloudflare.com/client/v4/zones/zone789/dns_records' => ['success' => true, 'result' => ['id' => 'rec1']],
        'cloudflare-dns.com/*' => ['Status' => 0, 'Answer' => [['data' => '104.21.82.118']]],
    ]);
}

it('refuses a tunnel configured by a local file', function () {
    Http::fake(['api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel/tun456/configurations' => Http::response(['success' => true, 'result' => ['config' => null, 'source' => 'local']])]);

    expect(fn () => PublishCloudflareTunnelHostname::run($this->server, 'srv.example.com', 'cf-api-token'))
        ->toThrow(RuntimeException::class, 'local file');
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

it('refuses to replace a DNS record that is not a CNAME', function () {
    publishHostnameHttpFake([
        'api.cloudflare.com/client/v4/zones/zone789/dns_records?*' => ['success' => true, 'result' => [['id' => 'rec1', 'type' => 'A']]],
    ]);

    expect(fn () => PublishCloudflareTunnelHostname::run($this->server, 'srv.example.com', 'cf-api-token'))
        ->toThrow(RuntimeException::class, 'already has a A record');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dns_records') && in_array($request->method(), ['PUT', 'POST']));
});

it('names the missing Cloudflare Tunnel permission when the tunnel API refuses', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1001, 'message' => 'Not authorized']]], 401)]);

    expect(fn () => PublishCloudflareTunnelHostname::run($this->server, 'srv.example.com', 'cf-api-token'))
        ->toThrow(RuntimeException::class, 'Cloudflare Tunnel Write');
});

it('waits until the new hostname resolves publicly before returning', function () {
    publishHostnameHttpFake([
        'cloudflare-dns.com/*' => Http::sequence()->push(['Status' => 3])->push(['Status' => 3])->push(['Status' => 0, 'Answer' => [['data' => '104.21.82.118']]]),
    ]);

    PublishCloudflareTunnelHostname::run($this->server, 'srv.example.com', 'cf-api-token');

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['config']['ingress'] === [
        ['hostname' => 'app.example.com', 'service' => 'http://localhost:80'],
        ['hostname' => 'srv.example.com', 'service' => 'ssh://localhost:22'],
        ['service' => 'http_status:404'],
    ]);
    Sleep::assertSleptTimes(2);
});
