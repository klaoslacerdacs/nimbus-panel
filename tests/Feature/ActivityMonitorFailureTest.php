<?php

use App\Livewire\ActivityMonitor;
use App\Livewire\Server\ValidateAndInstall;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

test('a process that exits with an error dispatches activityFailed instead of the success event', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'install docker',
        'properties' => ['team_id' => $this->team->id, 'server_uuid' => $server->uuid, 'exitCode' => 1, 'status' => 'error'],
    ]);

    Livewire::test(ActivityMonitor::class)
        ->call('newMonitorActivity', $activity->id, 'init', 2)
        ->call('polling')
        ->assertSet('isPollingActive', false)
        ->assertNotDispatched('init')
        ->assertDispatched('activityFailed');
});

test('the validation screen shows an error and keeps the logs when the installation fails', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(ValidateAndInstall::class, ['server' => $server, 'ask' => true])
        ->set('ask', false)
        ->set('isInstalling', true)
        ->set('installationStep', 'Docker')
        ->dispatch('activityFailed')
        ->assertSet('isInstalling', true)
        ->assertSee('Docker installation failed')
        ->assertSee('Retry validation');

    expect($server->fresh()->validation_logs)->toContain('Docker installation failed');
});
