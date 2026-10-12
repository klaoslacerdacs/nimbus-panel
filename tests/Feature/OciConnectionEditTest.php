<?php

use App\Livewire\Security\OciConnectionEdit;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function makeConnection(Team $team): OciConnection
{
    return OciConnection::create([
        'team_id' => $team->id,
        'name' => 'Test Conn',
        'authentication_method' => 'api_key',
        'region' => 'us-ashburn-1',
        'tenancy_ocid' => 'ocid1.tenancy.oc1..aaa',
        'user_ocid' => 'ocid1.user.oc1..bbb',
        'fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
        'private_key' => "-----BEGIN RSA PRIVATE KEY-----\noriginal\n-----END RSA PRIVATE KEY-----",
    ]);
}

function mockValidClient(): MockInterface
{
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')->andReturn(['ok' => true]);

    return $mock;
}

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->connection = makeConnection($this->team);
});

it('renders the edit form with existing values', function () {
    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->assertSet('name', 'Test Conn')
        ->assertSet('region', 'us-ashburn-1')
        ->assertSet('private_key', null)   // PEM never loaded into browser state
        ->assertStatus(200);
});

it('updates metadata without rotating PEM', function () {
    app()->instance(OciClient::class, mockValidClient());

    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->set('name', 'Updated Name')
        ->set('region', 'eu-frankfurt-1')
        ->call('updateConnection')
        ->assertDispatched('success');

    $this->assertDatabaseHas('oci_connections', [
        'id' => $this->connection->id,
        'name' => 'Updated Name',
        'region' => 'eu-frankfurt-1',
    ]);

    // Original PEM preserved (not overwritten with null).
    $fresh = $this->connection->fresh();
    expect($fresh->private_key)->toContain('original');
});

it('rotates PEM when new key provided', function () {
    app()->instance(OciClient::class, mockValidClient());

    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->update(
            calls: [['method' => 'updateConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\nrotated\n-----END RSA PRIVATE KEY-----"],
        )
        ->assertDispatched('success');

    expect($this->connection->fresh()->private_key)->toContain('rotated');
});

it('does not update when OCI API returns error', function () {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')->andReturn(['error' => true, 'message' => 'Bad creds']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->set('name', 'Should Not Save')
        ->call('updateConnection')
        ->assertDispatched('error');

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Should Not Save']);
});

it('clears PEM from snapshot after updateConnection', function () {
    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->update(
            calls: [['method' => 'updateConnection', 'params' => [], 'path' => '']],
            updates: [
                'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nsecret\n-----END RSA PRIVATE KEY-----",
                'name' => '',   // force validation error so we can assert null without OCI call
            ],
        )
        ->assertHasErrors(['name'])
        ->assertSet('private_key', null);
});

it('blocks cross-team IDOR — other team cannot edit', function () {
    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($otherUser, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    $this->withoutVite();

    // updateConnection scopes to ownedByCurrentTeam → ModelNotFoundException → 404
    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->call('updateConnection')
        ->assertStatus(404);

    $this->assertDatabaseHas('oci_connections', ['name' => 'Test Conn', 'team_id' => $this->team->id]);
});

it('denies update to non-admin members', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    expect($member->cannot('update', $this->connection))->toBeTrue();

    $this->withoutVite();

    // mount() dispatches 'error' (auth denied) and leaves connectionId=0.
    // A direct call() then hits findOrFail(0) → ModelNotFoundException → abort(404).
    Livewire::test(OciConnectionEdit::class, ['connection' => $this->connection])
        ->call('updateConnection')
        ->assertStatus(404);

    $this->assertDatabaseHas('oci_connections', ['name' => 'Test Conn']);
});

it('switching to instance_principal drops stored API key material', function () {
    $root = Team::factory()->create(['id' => 0]);
    $root->members()->attach($this->user, ['role' => 'admin']);
    $this->user->refresh();

    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')->once()->andReturn(['ok' => true, 'tenancy_ocid' => 'ocid1.tenancy.oc1..host']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciConnectionEdit::class, ['connection' => $connection])
        ->set('authentication_method', 'instance_principal')
        ->call('updateConnection')
        ->assertHasNoErrors();

    $connection->refresh();
    expect($connection->authentication_method->value)->toBe('instance_principal')
        ->and($connection->private_key)->toBeNull()
        ->and($connection->user_ocid)->toBeNull()
        ->and($connection->tenancy_ocid)->toBe('ocid1.tenancy.oc1..host');
});
