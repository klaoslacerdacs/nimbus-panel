<?php

use App\Events\CloudflareTunnelChanged;
use App\Events\CloudflareTunnelConfigured;
use App\Listeners\CloudflareTunnelChangedNotification;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Sleep::fake();
    Event::fake([CloudflareTunnelConfigured::class]);

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'ubuntu',
        'ip' => '203.0.113.20',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
});

it('marks the tunnel configured on servers with a non-root SSH user', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: 'healthy');
    });

    (new CloudflareTunnelChangedNotification)->handle(new CloudflareTunnelChanged(['server_id' => $this->server->id, 'ssh_domain' => 'ssh.example.com']));

    expect(collect($commands)->first(fn ($c) => str_contains($c, 'coolify-cloudflared')))->toContain('sudo docker inspect')
        ->and((bool) $this->server->fresh()->settings->is_cloudflare_tunnel)->toBeTrue()
        ->and($this->server->fresh()->ip)->toBe('ssh.example.com');
});

it('does not mark the tunnel when the container is not healthy', function (): void {
    Process::fake(fn () => Process::result(output: 'starting'));

    (new CloudflareTunnelChangedNotification)->handle(new CloudflareTunnelChanged(['server_id' => $this->server->id, 'ssh_domain' => 'ssh.example.com']));

    expect((bool) $this->server->fresh()->settings->is_cloudflare_tunnel)->toBeFalse()
        ->and($this->server->fresh()->ip)->toBe('203.0.113.20');
});
