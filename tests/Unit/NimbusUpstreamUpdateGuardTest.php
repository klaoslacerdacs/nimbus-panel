<?php

use App\Actions\Server\UpdateCoolify;
use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
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
