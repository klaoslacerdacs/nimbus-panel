<?php

use App\Actions\Server\RenameServerHostname;
use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['cache.default' => 'array', 'session.driver' => 'array']);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'key',
        'private_key' => <<<'KEY_WRAP'
        -----BEGIN OPENSSH PRIVATE KEY-----
        b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
        QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
        hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
        AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
        uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
        -----END OPENSSH PRIVATE KEY-----
        KEY_WRAP,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'name' => 'old-name',
        'ip' => '1.2.3.4',
    ]);
    $this->server->settings->update(['is_reachable' => true, 'server_timezone' => 'UTC']);
});

it('renames the OS hostname of reachable OCI servers', function (): void {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $this->server->update(['oci_connection_id' => $connection->id]);

    RenameServerHostname::shouldRun()->once()->withArgs(fn ($server, $name): bool => $name === 'new-name');

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('name', 'new-name')
        ->call('submit')
        ->assertNotDispatched('error');
});

it('does not touch the OS hostname of non-OCI servers', function (): void {
    RenameServerHostname::shouldNotRun();

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('name', 'new-name')
        ->call('submit');

    expect($this->server->fresh()->name)->toBe('new-name');
});

it('does not touch the OS hostname when saving fails validation', function (): void {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $this->server->update(['oci_connection_id' => $connection->id]);

    RenameServerHostname::shouldNotRun();

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('name', 'new-name')
        ->set('serverTimezone', 'Not/AZone')
        ->call('submit');
});
