<?php

use App\Livewire\Project\Shared\Logs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\RuntimeNetworkSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
    ));
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
});

test('a service container lists its exposed ports and the domain route, warning when they disagree', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id, 'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  bento-pdf:\n    image: ghcr.io/alam00000/bentopdf:v1\n    environment:\n      - SERVICE_URL_BENTOPDF_8080\n",
    ]);
    ServiceApplication::create([
        'uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'bento-pdf', 'image' => 'ghcr.io/alam00000/bentopdf:v1',
        'fqdn' => 'https://pdf.example.com', 'domain_port_overrides' => ['https://pdf.example.com' => 8080],
    ]);
    $service = $service->fresh(['applications']);
    $container = fn (string $ports) => ['ID' => 'c1', 'Names' => "bento-pdf-{$service->uuid}", 'State' => 'running', 'Ports' => $ports];

    expect(RuntimeNetworkSummary::build($service, [$container('8080/tcp')]))->toBe([[
        'container' => "bento-pdf-{$service->uuid}", 'exposed' => [8080], 'published' => [],
        'routes' => [['url' => 'https://pdf.example.com', 'port' => 8080]], 'warnings' => [],
    ]]);

    $mismatch = RuntimeNetworkSummary::build($service, [$container('80/tcp')]);
    expect($mismatch[0]['warnings'])->toHaveCount(1)
        ->and($mismatch[0]['warnings'][0])->toContain('only 80')->toContain('routes to 8080');

    // An image without EXPOSE says nothing about the port, so no warning.
    expect(RuntimeNetworkSummary::build($service, [$container('')])[0])->toMatchArray(['exposed' => [], 'warnings' => []]);

    Livewire::test(Logs::class)
        ->set('resource', $service)->set('type', 'service')
        ->set('servers', collect([$this->server]))
        ->set('serverContainers', [$this->server->id => [$container('8080/tcp')]])
        ->set('containersLoaded', true)
        ->call('syncLogSelection')
        ->assertSee('Exposed ports')
        ->assertSee('https://pdf.example.com → 8080', false)
        ->assertSee('Proxy not running');

    $this->server->proxy->set('type', 'TRAEFIK');
    $this->server->proxy->set('status', 'running');
    $this->server->save();
    Livewire::test(Logs::class)
        ->set('resource', $service)->set('type', 'service')
        ->set('servers', collect([$this->server->fresh()]))
        ->set('serverContainers', [$this->server->id => [$container('8080/tcp')]])
        ->set('containersLoaded', true)
        ->call('syncLogSelection')
        ->assertDontSee('Proxy not running');
});

test('an application container shows published host ports once and routes to its exposed port', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com,https://api.example.com:4000', 'ports_exposes' => '3000',
    ]);

    $row = ['ID' => 'a', 'Names' => 'app', 'State' => 'running', 'Ports' => '0.0.0.0:3000->3000/tcp, [::]:3000->3000/tcp, 4000-4002/tcp'];
    expect(RuntimeNetworkSummary::build($application, [$row, $row]))->toBe([[
        'container' => 'app', 'exposed' => [3000, 4000, 4001, 4002], 'published' => ['0.0.0.0:3000->3000/tcp'],
        'routes' => [['url' => 'https://app.example.com', 'port' => 3000], ['url' => 'https://api.example.com', 'port' => 4000]], 'warnings' => [],
    ]]);
});

test('a compose application maps each container to its own domains and compose port', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose', 'fqdn' => null, 'ports_exposes' => '',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx\n    ports:\n      - 8081:80\n  api:\n    image: node\n    expose:\n      - 3000\n",
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://web.example.com'], 'api' => ['domain' => 'https://api.example.com']]),
    ]);

    $summary = RuntimeNetworkSummary::build($application, [
        ['ID' => 'w', 'Names' => 'web-abc', 'Ports' => '80/tcp', 'Labels' => 'com.docker.compose.service=web'],
        ['ID' => 'a', 'Names' => 'api-abc', 'Ports' => '', 'Labels' => 'com.docker.compose.service=api'],
    ]);

    expect(collect($summary)->pluck('routes', 'container')->all())->toBe([
        'web-abc' => [['url' => 'https://web.example.com', 'port' => 80]],
        'api-abc' => [['url' => 'https://api.example.com', 'port' => 3000]],
    ]);
});
