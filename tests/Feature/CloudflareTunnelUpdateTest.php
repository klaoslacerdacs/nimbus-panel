<?php

use App\Actions\Server\ConfigureCloudflared;
use App\Jobs\CoolifyTask;
use App\Livewire\Server\CloudflareTunnel;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
