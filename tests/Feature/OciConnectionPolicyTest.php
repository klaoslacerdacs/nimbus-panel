<?php

use App\Enums\OciAuthenticationMethod;
use App\Models\OciConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admin to create connection', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);

    expect($admin->isAdminOfTeam($team->id))->toBeTrue();
});

it('denies member from deleting connection', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    expect($member->isAdminOfTeam($team->id))->toBeFalse();
});

it('isolates connections by team', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $connA = OciConnection::factory()->for($teamA)->create();
    $connB = OciConnection::factory()->for($teamB)->create();

    $teamAConns = OciConnection::ownedByTeam($teamA->id)->get();
    $teamBConns = OciConnection::ownedByTeam($teamB->id)->get();

    expect($teamAConns)->toHaveCount(1)
        ->and($teamBConns)->toHaveCount(1)
        ->and($teamAConns->first()->id)->toBe($connA->id)
        ->and($teamBConns->first()->id)->toBe($connB->id);
});

it('hides private key and passphrase from array output', function () {
    $team = Team::factory()->create();
    $connection = OciConnection::factory()->for($team)->create([
        'private_key' => 'secret-key',
        'passphrase' => 'secret-pass',
    ]);

    $array = $connection->toArray();

    expect($array)->not->toHaveKey('private_key')
        ->and($array)->not->toHaveKey('passphrase');
});

it('supports instance principal without api key fields', function () {
    $team = Team::factory()->create();
    $connection = OciConnection::factory()->for($team)->create([
        'authentication_method' => OciAuthenticationMethod::INSTANCE_PRINCIPAL,
        'tenancy_ocid' => null,
        'user_ocid' => null,
        'fingerprint' => null,
        'private_key' => null,
        'passphrase' => null,
    ]);

    expect($connection->authentication_method)->toBe(OciAuthenticationMethod::INSTANCE_PRINCIPAL)
        ->and($connection->tenancy_ocid)->toBeNull();
});
