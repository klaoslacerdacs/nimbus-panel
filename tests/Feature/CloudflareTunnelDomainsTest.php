<?php

use App\Jobs\ConfigureDnsRecordJob;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Project\Service\Domains as ServiceDomains;
use App\Models\Application;
use App\Models\CloudflareConnection;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_dns_validation_enabled' => false]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = PrivateKey::factory()->create(['team_id' => $this->team->id])->id;
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $keyId, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
        );
    });
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);

    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);

    $tunnelToken = base64_encode(json_encode(['a' => 'acc123', 't' => 'tun456', 's' => 'secret']));
    Process::fake([
        '*for dir in*' => Process::result(output: '/data/coolify/cloudflared'),
        '*TUNNEL_TOKEN*' => Process::result(output: "      - TUNNEL_TOKEN={$tunnelToken}"),
        '*' => Process::result(output: ''),
    ]);
    Http::fake([
        'api.cloudflare.com/client/v4/zones?account.id*' => ['success' => true, 'result' => [['name' => 'example.com']]],
        'api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel/tun456/configurations' => ['success' => true, 'result' => ['config' => null, 'source' => 'cloudflare']],
        'api.cloudflare.com/client/v4/zones?name=*' => ['success' => true, 'result' => [['id' => 'zone1']]],
        'api.cloudflare.com/client/v4/zones/zone1/dns_records?*' => ['success' => true, 'result' => []],
        'api.cloudflare.com/client/v4/zones/zone1/dns_records' => ['success' => true, 'result' => ['id' => 'rec1']],
        'cloudflare-dns.com/*' => ['Status' => 0, 'Answer' => [['data' => '104.21.82.118']]],
    ]);
});

function tunnelDomainsApplication(): Application
{
    return Application::factory()->create([
        'uuid' => (string) Str::uuid(), 'name' => 'Tunnel App',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id, 'destination_type' => test()->destination->getMorphClass(),
        'fqdn' => null, 'redirect' => 'both', 'build_pack' => 'nixpacks',
    ]);
}

/** @return list<array<string, mixed>> ingress rules sent in tunnel config PUTs */
function publishedIngress(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'PUT' && str_contains($pair[0]->url(), 'configurations'))
        ->flatMap(fn ($pair) => $pair[0]['config']['ingress'])
        ->filter(fn (array $rule): bool => isset($rule['hostname']))
        ->values()->all();
}

test('publishes an https domain and its www pair through the tunnel to Traefik on 443', function () {
    $application = tunnelDomainsApplication();

    Livewire::test(Domains::class, ['application' => $application])
        ->assertSee('Publish through Cloudflare Tunnel')
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertHasNoErrors()
        ->assertDispatched('success', 'my-app.example.com is published through the Cloudflare Tunnel. Restart to apply.');

    expect($application->fresh()->fqdn)->toContain('https://my-app.example.com')
        ->and(collect(publishedIngress())->keyBy('hostname')->all())->toMatchArray([
            'my-app.example.com' => ['hostname' => 'my-app.example.com', 'service' => 'https://localhost:443', 'originRequest' => ['noTLSVerify' => true, 'originServerName' => 'my-app.example.com']],
            'www.my-app.example.com' => ['hostname' => 'www.my-app.example.com', 'service' => 'https://localhost:443', 'originRequest' => ['noTLSVerify' => true, 'originServerName' => 'www.my-app.example.com']],
        ]);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['type'] === 'CNAME' && $request['name'] === 'my-app.example.com' && $request['content'] === 'tun456.cfargotunnel.com');
});

test('an http domain goes to Traefik on port 80 without TLS options', function () {
    $application = tunnelDomainsApplication();

    Livewire::test(Domains::class, ['application' => $application])
        ->set('cloudflareTunnelScheme', 'http')
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertHasNoErrors();

    expect(collect(publishedIngress())->firstWhere('hostname', 'my-app.example.com'))
        ->toBe(['hostname' => 'my-app.example.com', 'service' => 'http://localhost:80']);
});

test('refuses a domain that is not on the connected Cloudflare account', function () {
    $application = tunnelDomainsApplication();

    Livewire::test(Domains::class, ['application' => $application])
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'evil.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertHasErrors(['cloudflareTunnelZone']);

    expect($application->fresh()->fqdn)->toBeNull()
        ->and(publishedIngress())->toBe([]);
});

test('does not publish when the domain is already configured', function () {
    $application = tunnelDomainsApplication();
    $application->update(['fqdn' => 'https://my-app.example.com']);

    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertNotDispatched('success');

    expect(publishedIngress())->toBe([]);
});

test('members cannot publish a domain through the tunnel', function () {
    $application = tunnelDomainsApplication();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Domains::class, ['application' => $application])
        ->assertDontSee('Publish through Cloudflare Tunnel')
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertForbidden();

    // Not even the tunnel lookup on the server runs for a member.
    Process::assertNothingRan();
    expect($application->fresh()->fqdn)->toBeNull()
        ->and(publishedIngress())->toBe([]);
});

test('publishes a service application domain through the tunnel', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    $web = ServiceApplication::create([
        'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'human_name' => 'Web',
        'image' => 'nginx:alpine', 'fqdn' => null,
    ]);

    Livewire::test(ServiceDomains::class, ['service' => $service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $web->id)
        ->set('cloudflareTunnelLabel', 'chat')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertHasNoErrors();

    expect($web->fresh()->fqdn)->toContain('https://chat.example.com')
        ->and(collect(publishedIngress())->pluck('hostname'))->toContain('chat.example.com');
});

test('offers Login with Cloudflare when the team has no connection', function () {
    CloudflareConnection::query()->delete();
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'cf', 'cloudflare_oauth_client_secret' => 's']);

    Livewire::test(Domains::class, ['application' => tunnelDomainsApplication()])
        ->assertSee('Login with Cloudflare');
});

test('hides the section when Cloudflare login is not available and the team is not connected', function () {
    CloudflareConnection::query()->delete();

    Livewire::test(Domains::class, ['application' => tunnelDomainsApplication()])
        ->assertDontSee('Publish through Cloudflare Tunnel');
});

test('adds the container port a service template requires', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n      - SERVICE_URL_WEB_3000\n",
    ]);
    $web = ServiceApplication::create([
        'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'human_name' => 'Web',
        'image' => 'nginx:alpine', 'fqdn' => null,
    ]);
    expect($web->fresh()->getRequiredPort())->toBe(3000);

    Livewire::test(ServiceDomains::class, ['service' => $service->fresh(['applications', 'server'])])
        ->set('newServiceApplicationId', $web->id)
        ->set('cloudflareTunnelLabel', 'chat')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertHasNoErrors()
        ->assertSet('showPortWarningModal', false);

    expect($web->fresh()->fqdn)->toContain('https://chat.example.com')
        ->and($web->fresh()->domain_port_overrides)->toMatchArray(['https://chat.example.com' => 3000])
        ->and(collect(publishedIngress())->firstWhere('hostname', 'chat.example.com'))->toMatchArray(['service' => 'https://localhost:443']);
});

test('publishes without the token A record, marks the DNS as handled by the tunnel, and shows the container port', function () {
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'capabilities' => ['dns'], 'token' => 'cf-api-secret']);
    DnsProviderZone::factory()->for($token)->create(['provider_zone_id' => 'zone1', 'name' => 'example.com', 'account_id' => 'acc123']);
    $application = tunnelDomainsApplication();

    Livewire::test(Domains::class, ['application' => $application])
        ->assertSee('Reaches container port 3000')
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertSet('domainRows', fn (array $rows): bool => collect($rows)->firstWhere('url', 'https://my-app.example.com')['dns_message'] === 'DNS record created through the Cloudflare Tunnel.');

    Queue::assertNotPushed(ConfigureDnsRecordJob::class);
});

test('refuses to publish before the server has the automated tunnel, saving nothing', function () {
    Process::fake(['*TUNNEL_TOKEN*' => Process::result(output: '')]);
    $application = tunnelDomainsApplication();

    Livewire::test(Domains::class, ['application' => $application])
        ->set('cloudflareTunnelLabel', 'my-app')
        ->set('cloudflareTunnelZone', 'example.com')
        ->call('publishThroughCloudflareTunnel')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'Set up the Cloudflare Tunnel on'));

    expect($application->fresh()->fqdn)->toBeNull()->and(publishedIngress())->toBe([]);
    Queue::assertNotPushed(ConfigureDnsRecordJob::class);
});
