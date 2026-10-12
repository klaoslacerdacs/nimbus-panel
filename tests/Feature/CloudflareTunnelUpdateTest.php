<?php

use App\Actions\Server\ConfigureCloudflared;
use App\Jobs\CoolifyTask;
use App\Livewire\Server\CloudflareTunnel;
use App\Models\CloudflareConnection;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Server::flushIdentityMap();
    Queue::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'ip' => 'ssh.example.com',
    ]);
    $this->server->settings->update(['is_cloudflare_tunnel' => true]);
});

function actingAsCloudflareTunnelUpdateUser(Team $team, string $role): void
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('update pulls and recreates cloudflared from the compose file that older versions wrote to /tmp', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    Process::fake(['*' => Process::result(output: '/tmp/cloudflared')]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->call('updateCloudflareTunnel')
        ->assertDispatched('cloudflare-tunnel-update-started');

    Queue::assertPushed(CoolifyTask::class);
    $command = RemoteProcessCommand::read(Activity::query()->latest('id')->firstOrFail());
    expect($command)->toContain('cd /tmp/cloudflared')
        ->toContain('docker compose pull')
        ->toContain('nohup setsid sh -c');
});

test('update stops with an error when the cloudflared compose file is missing', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    Process::fake(['*' => Process::result(output: '')]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->call('updateCloudflareTunnel')
        ->assertNotDispatched('cloudflare-tunnel-update-started');

    Queue::assertNothingPushed();
});

test('members without update rights cannot update the tunnel', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'member');
    Process::fake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->call('updateCloudflareTunnel')
        ->assertNotDispatched('cloudflare-tunnel-update-started');

    Process::assertNothingRan();
    Queue::assertNothingPushed();
});

test('update reports the real SSH failure instead of a missing configuration', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    Process::fake(['*' => Process::result(output: '', errorOutput: 'tls: failed to verify certificate: x509', exitCode: 255)]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->call('updateCloudflareTunnel')
        ->assertNotDispatched('cloudflare-tunnel-update-started')
        ->assertDispatched('error', fn (string $event, array $params) => ! str_contains(implode(' ', array_map('strval', $params)), 'was not found'));
});

test('automated setup requires a full SSH hostname, not an IP or a bare name', function (string $sshDomain) {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    ConfigureCloudflared::shouldNotRun();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_token', 'token')
        ->set('ssh_domain', $sshDomain)
        ->call('automatedCloudflareConfig')
        ->assertHasErrors(['ssh_domain']);
})->with(['srv-oci-coolify', '163.176.137.233', 'ssh..example.com']);

test('manual setup is refused while the server address is still an IP', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->call('manualCloudflareConfig')
        ->assertDispatched('error');

    expect((bool) $this->server->fresh()->settings->is_cloudflare_tunnel)->toBeFalse();
});

test('automated setup accepts a hostname pasted with its https:// prefix', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    ConfigureCloudflared::shouldRun()->once()->withArgs(fn ($server, $token, $domain) => $domain === 'ssh-server.example.com')
        ->andReturn(Activity::create(['description' => 'x', 'log_name' => 'default']));

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflare_token', 'token')
        ->set('ssh_domain', 'https://ssh-server.example.com/')
        ->call('automatedCloudflareConfig')
        ->assertHasNoErrors();
});

function cloudflareFixProcessFake(bool $sshThroughTunnelWorks): void
{
    $tunnelToken = base64_encode(json_encode(['a' => 'acc123', 't' => 'tun456', 's' => 'secret']));
    Process::fake(function ($process) use ($tunnelToken, $sshThroughTunnelWorks) {
        return match (true) {
            str_contains($process->command, 'for dir in') => Process::result(output: '/data/coolify/cloudflared'),
            str_contains($process->command, 'TUNNEL_TOKEN') => Process::result(output: "      - TUNNEL_TOKEN={$tunnelToken}"),
            str_contains($process->command, 'coolify-cloudflared') => Process::result(output: 'healthy'),
            str_contains($process->command, 'cloudflared access ssh') && ! $sshThroughTunnelWorks => Process::result(output: '', errorOutput: 'websocket: bad handshake', exitCode: 255),
            default => Process::result(output: ''),
        };
    });
}

function cloudflareFixHttpFake(): void
{
    Http::fake([
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

test('fix automatically publishes the SSH hostname, creates DNS and switches SSH to the tunnel', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    cloudflareFixHttpFake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('success')
        ->assertSet('cloudflareApiToken', null);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), 'cfd_tunnel/tun456/configurations')
        && $request['config']['ingress'] === [
            ['hostname' => 'app.example.com', 'service' => 'http://localhost:80'],
            ['hostname' => 'srv-oci-coolify.example.com', 'service' => 'ssh://localhost:22'],
            ['service' => 'http_status:404'],
        ]
        && $request->header('Authorization')[0] === 'Bearer cf-api-token');
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['type'] === 'CNAME' && $request['content'] === 'tun456.cfargotunnel.com' && $request['proxied'] === true);

    expect((bool) $this->server->fresh()->settings->is_cloudflare_tunnel)->toBeTrue()
        ->and($this->server->fresh()->ip)->toBe('srv-oci-coolify.example.com');
});

test('fix automatically keeps direct SSH when the tunnel hostname does not work yet', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: false);
    cloudflareFixHttpFake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('error');

    expect((bool) $this->server->fresh()->settings->is_cloudflare_tunnel)->toBeFalse()
        ->and($this->server->fresh()->ip)->toBe('163.176.137.233');
});

test('fix automatically surfaces Cloudflare API errors without touching the server', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Authentication error']]], 403)]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'bad-token'])
        ->assertDispatched('error');

    expect($this->server->fresh()->ip)->toBe('163.176.137.233');
});

test('members cannot run the automatic tunnel fix', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'member');
    Http::fake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token']);

    Http::assertNothingSent();
});

test('fix automatically uses the team Cloudflare login instead of an API token', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123', 'access_token' => 'oauth-token']);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    cloudflareFixHttpFake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Using Nimbus Network')
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->call('fixSshThroughTunnel')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), 'cfd_tunnel/tun456/configurations')
        && $request->header('Authorization')[0] === 'Bearer oauth-token');
});

test('fix automatically refuses a login of another Cloudflare account than the tunnel', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'other-account']);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    Http::fake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->call('fixSshThroughTunnel')
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cfd_tunnel') || str_contains($request->url(), 'dns_records'));
    expect($this->server->fresh()->ip)->toBe('163.176.137.233');
});

test('shows the Cloudflare login button when the OAuth client is configured and the team is not connected', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'cf', 'cloudflare_oauth_client_secret' => 's', 'cloudflare_oauth_scopes' => 'tunnel:edit']);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Login with Cloudflare')
        ->assertSee(e(route('cloudflare.connect', ['return' => '/server/'.$this->server->uuid.'/cloudflare-tunnel'])), false)
        ->assertDontSee('Fix automatically');
});

test('fix automatically refuses to replace a DNS record that is not a CNAME', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel/tun456/configurations' => Http::response(['success' => true, 'result' => ['config' => ['ingress' => [['service' => 'http_status:404']]]]]),
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/client/v4/zones/zone1/dns_records*' => Http::response(['success' => true, 'result' => [['id' => 'rec1', 'type' => 'A']]]),
    ]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dns_records') && in_array($request->method(), ['PUT', 'POST']));
    expect($this->server->fresh()->ip)->toBe('163.176.137.233');
});

test('suggests an SSH hostname from the server name and preselects the panel domain zone', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    InstanceSettings::get()->update(['fqdn' => 'https://painel.nimbus.dev']);
    $this->server->update(['name' => 'Uptight Unicorn']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake(['api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [['name' => 'nimbus.dev'], ['name' => 'example.com']]])]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('fixSshLabel', 'ssh-uptight-unicorn')
        ->assertSet('fixSshZone', 'nimbus.dev');
});

test('only accepts a domain of the connected account', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake(['api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => [['name' => 'example.com']]])]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshLabel', 'ssh-x')
        ->set('fixSshZone', 'evil.com')
        ->call('fixSshThroughTunnel')
        ->assertHasErrors(['fixSshZone']);
});

test('leaves the SSH hostname empty when the zones cannot be listed', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 403)]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('fixSshHostname', '');
});

test('names the missing Cloudflare Tunnel permission when the tunnel API refuses', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1001, 'message' => 'Not authorized']]], 401)]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains(json_encode($params), 'Cloudflare Tunnel Write'));
});

test('fix automatically publishes on a dashboard tunnel that has no hostnames yet', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel/tun456/configurations' => Http::sequence()
            ->push(['success' => true, 'result' => ['version' => 0, 'config' => null, 'source' => 'cloudflare']])
            ->push(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/client/v4/zones/zone1/dns_records*' => Http::response(['success' => true, 'result' => []]),
    ]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('success');

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_contains($request->url(), 'configurations')
        && $request['config']['ingress'] === [
            ['hostname' => 'srv-oci-coolify.example.com', 'service' => 'ssh://localhost:22'],
            ['service' => 'http_status:404'],
        ]);
});

test('fix automatically refuses a tunnel configured by a local file', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    Http::fake(['api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel/tun456/configurations' => Http::response(['success' => true, 'result' => ['config' => null, 'source' => 'local']])]);

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

test('waits until the new SSH hostname resolves publicly before checking SSH', function () {
    actingAsCloudflareTunnelUpdateUser($this->team, 'owner');
    $this->server->update(['ip' => '163.176.137.233']);
    $this->server->settings->update(['is_cloudflare_tunnel' => false, 'is_reachable' => true, 'is_usable' => true]);
    cloudflareFixProcessFake(sshThroughTunnelWorks: true);
    // Registered first so it wins over the resolving answer in cloudflareFixHttpFake().
    Http::fake(['cloudflare-dns.com/*' => Http::sequence()->push(['Status' => 3])->push(['Status' => 3])->push(['Status' => 0, 'Answer' => [['data' => '104.21.82.118']]])]);
    cloudflareFixHttpFake();

    Livewire::test(CloudflareTunnel::class, ['server_uuid' => $this->server->uuid])
        ->set('fixSshHostname', 'srv-oci-coolify.example.com')
        ->update(calls: [['method' => 'fixSshThroughTunnel', 'params' => [], 'path' => '']], updates: ['cloudflareApiToken' => 'cf-api-token'])
        ->assertDispatched('success');

    Sleep::assertSleptTimes(2);
});
