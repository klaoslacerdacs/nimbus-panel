<?php

use App\Livewire\Server\DockerRegistries\Login;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->refresh();

    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    $this->connection = OciConnection::factory()->create(['team_id' => $this->team->id, 'region' => 'sa-saopaulo-1']);
});

it('recognizes OCIR hosts as the Oracle Cloud provider', function (string $host): void {
    expect(Login::providerFor($host))->toBe('oracle');
})->with(['gru.ocir.io', 'iad.ocir.io', 'ocir.sa-saopaulo-1.oci.oraclecloud.com']);

it('only accepts OCIR hosts for the Oracle Cloud provider', function (): void {
    Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', 'oracle')
        ->set('registry', 'ghcr.io')
        ->set('username', 'ns/user')
        ->set('password', 'token')
        ->call('login')
        ->assertHasErrors(['registry']);
});

it('fills the OCIR host and namespace username from an OCI connection', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getRegistryLogin')->once()
        ->with(Mockery::on(fn ($config) => $config['region'] === 'sa-saopaulo-1'))
        ->andReturn(['registry' => 'gru.ocir.io', 'username' => 'axnamespace/klaos@nimbus']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', 'oracle')
        ->set('ociConnectionId', $this->connection->id)
        ->assertSet('registry', 'gru.ocir.io')
        ->assertSet('username', 'axnamespace/klaos@nimbus');
});

it('never reads an OCI connection of another team', function (): void {
    $foreign = OciConnection::factory()->create(['team_id' => Team::factory()->create()->id]);
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldNotReceive('getRegistryLogin');
    app()->instance(OciClient::class, $mock);

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', 'oracle')
        ->set('ociConnectionId', $foreign->id)
        ->assertSet('registry', '');
});

it('logs the server in to OCIR with the auth token on stdin', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: '');
    });

    Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', 'oracle')
        ->set('registry', 'GRU.ocir.io')
        ->set('username', 'axnamespace/klaos@nimbus')
        ->set('password', 'oci-auth-token')
        ->call('login')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(collect($commands)->first(fn (string $c) => str_contains($c, ' login ')))
        ->toContain("login --username 'axnamespace/klaos@nimbus' --password-stdin 'gru.ocir.io'")
        ->not->toContain('oci-auth-token');
});

it('asks to complete an OCIR username that stops at the namespace', function (): void {
    Livewire::test(Login::class, ['server' => $this->server])
        ->set('provider', 'oracle')
        ->set('registry', 'gru.ocir.io')
        ->set('username', 'gr20kqplx0hg/')
        ->set('password', 'token')
        ->call('login')
        ->assertHasErrors(['username']);
});
