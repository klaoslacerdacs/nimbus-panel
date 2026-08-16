<?php

use App\Enums\OciAuthenticationMethod;
use App\Models\OciConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('belongs to a team', function () {
    $team = Team::factory()->create();
    $connection = OciConnection::factory()->for($team)->create();

    expect($connection->team->is($team))->toBeTrue()
        ->and($team->ociConnections->contains($connection))->toBeTrue();
});

it('defaults to api key authentication', function () {
    $connection = new OciConnection;

    expect($connection->authentication_method)->toBe(OciAuthenticationMethod::API_KEY);
});

it('allows instance principal credentials to remain null', function () {
    $connection = OciConnection::factory()->create([
        'authentication_method' => OciAuthenticationMethod::INSTANCE_PRINCIPAL,
        'region' => null,
        'compartment_ocid' => null,
        'tenancy_ocid' => null,
        'user_ocid' => null,
        'fingerprint' => null,
        'private_key' => null,
        'passphrase' => null,
    ]);

    $stored = DB::table('oci_connections')->find($connection->id);

    expect($connection->authentication_method)->toBe(OciAuthenticationMethod::INSTANCE_PRINCIPAL)
        ->and($stored->private_key)->toBeNull()
        ->and($stored->passphrase)->toBeNull();
});

it('scopes connection queries to the requested team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $ownConnection = OciConnection::factory()->for($team)->create();
    $otherConnection = OciConnection::factory()->for($otherTeam)->create();

    $connections = OciConnection::ownedByTeam($team->id)->get();

    expect($connections->modelKeys())->toBe([$ownConnection->id])
        ->and($connections->contains($otherConnection))->toBeFalse();
});

it('does not allow team ownership to be mass assigned', function () {
    $connection = new OciConnection([
        'name' => 'Production OCI',
        'authentication_method' => OciAuthenticationMethod::API_KEY,
        'team_id' => 123,
    ]);

    expect($connection->team_id)->toBeNull();
});

it('encrypts and hides api key secrets', function () {
    $privateKey = "-----BEGIN PRIVATE KEY-----\nprivate-key-contents\n-----END PRIVATE KEY-----";
    $connection = OciConnection::factory()->create([
        'authentication_method' => OciAuthenticationMethod::API_KEY,
        'tenancy_ocid' => 'ocid1.tenancy.oc1..example',
        'user_ocid' => 'ocid1.user.oc1..example',
        'fingerprint' => 'aa:bb:cc',
        'private_key' => $privateKey,
        'passphrase' => 'private-key-passphrase',
    ]);

    $stored = DB::table('oci_connections')->find($connection->id);
    $reloaded = $connection->fresh();
    $serialized = $connection->toArray();
    $json = $connection->toJson();

    expect($stored->private_key)->not->toContain('private-key-contents')
        ->and(Crypt::decryptString($stored->private_key))->toBe($privateKey)
        ->and($stored->passphrase)->not->toContain('private-key-passphrase')
        ->and(Crypt::decryptString($stored->passphrase))->toBe('private-key-passphrase')
        ->and($reloaded->private_key)->toBe($privateKey)
        ->and($reloaded->passphrase)->toBe('private-key-passphrase')
        ->and($serialized)->not->toHaveKeys(['private_key', 'passphrase'])
        ->and($json)->not->toContain('private_key')
        ->and($json)->not->toContain('passphrase');
});
