<?php

use App\Actions\Server\UpdateCoolify;
use App\Jobs\CheckForUpdatesJob;
use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.nimbus.allow_upstream_update' => false]);
    InstanceSettings::forceCreate(['id' => 0, 'is_auto_update_enabled' => true, 'new_version_available' => false]);
    Http::preventStrayRequests();
    Process::fake();
});

it('never upgrades a Nimbus install to an upstream Coolify release', function (): void {
    expect(fn () => (new UpdateCoolify)->handle(manual_update: true))
        ->toThrow(Exception::class, 'upstream Coolify updates are disabled');

    Process::assertNothingRan();
});

it('does not check the Coolify CDN for new versions', function (): void {
    (new CheckForUpdatesJob)->handle();

    Http::assertNothingSent();
    expect(InstanceSettings::get()->new_version_available)->toBeFalsy();
});

it('hides the upgrade button everywhere and refuses a forced upgrade call', function (): void {
    $root = Team::factory()->create(['id' => 0]);
    $admin = User::factory()->create();
    $root->members()->attach($admin, ['role' => 'owner']);
    $this->actingAs($admin);
    session(['currentTeam' => $root]);
    InstanceSettings::get()->update(['new_version_available' => true]);

    Livewire\Livewire::test(Upgrade::class, ['fullButton' => true])
        ->assertSet('isUpgradeAvailable', false)
        ->assertSee('No upgrade available')
        ->assertDontSee('New version available')
        ->call('upgrade')
        ->assertSet('updateInProgress', false);

    Process::assertNothingRan();
});
