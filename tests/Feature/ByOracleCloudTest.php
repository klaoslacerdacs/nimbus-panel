<?php

use App\Jobs\OciStackProvisionJob;
use App\Livewire\Server\New\ByOracleCloud;
use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders step 0 prerequisites by default', function (): void {
    $component = Livewire::test(ByOracleCloud::class);

    $component->assertStatus(200)->assertSet('current_step', 0);
});

it('can advance from step 0 to step 1 without validation', function (): void {
    $component = Livewire::test(ByOracleCloud::class)
        ->call('nextStep');

    $component->assertSet('current_step', 1);
});

it('can advance to step 2 by selecting connection', function (): void {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);

    $component = Livewire::test(ByOracleCloud::class)
        ->set('current_step', 1)
        ->set('selected_connection_id', $connection->id)
        ->call('nextStep');

    $component->assertSet('current_step', 2);
});

it('createServer creates Server and OciStack records', function (): void {
    Queue::fake();

    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(ByOracleCloud::class)
        ->set('current_step', 1)
        ->set('selected_connection_id', $connection->id)
        ->call('nextStep')
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-server')
        ->set('region', 'us-ashburn-1')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->set('availability_domain', 'MGTy:SA-SAOPAULO-1-AD-1')
        ->set('vcn_ocid', 'ocid1.vcn.oc1..example')
        ->set('subnet_ocid', 'ocid1.subnet.oc1..example')
        ->set('shape', 'VM.Standard.A1.Flex')
        ->set('image_id', 'ocid1.image.oc1..example')
        ->set('private_key_id', $privateKey->id)
        ->set('ssh_username', 'ubuntu')
        ->call('createServer');

    Queue::assertPushed(OciStackProvisionJob::class);

    $this->assertDatabaseHas('servers', [
        'name' => 'test-server',
        'oci_connection_id' => $connection->id,
        'oci_region' => 'us-ashburn-1',
        'private_key_id' => $privateKey->id,
        'team_id' => $this->team->id,
    ]);

    $this->assertDatabaseHas('oci_stacks', [
        'name' => 'test-server',
        'oci_connection_id' => $connection->id,
        'team_id' => $this->team->id,
        'status' => 'pending',
    ]);
});

it('createServer links oci_stack to server via server_id', function (): void {
    Queue::fake();

    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connection->id)
        ->set('server_name', 'test-linked')
        ->set('region', 'us-ashburn-1')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->set('availability_domain', 'MGTy:SA-SAOPAULO-1-AD-1')
        ->set('vcn_ocid', 'ocid1.vcn.oc1..example')
        ->set('subnet_ocid', 'ocid1.subnet.oc1..example')
        ->set('shape', 'VM.Standard.A1.Flex')
        ->set('image_id', 'ocid1.image.oc1..example')
        ->set('private_key_id', $privateKey->id)
        ->set('ssh_username', 'ubuntu')
        ->call('createServer');

    $server = Server::where('name', 'test-linked')->firstOrFail();
    $stack = OciStack::where('name', 'test-linked')->firstOrFail();

    expect($stack->server_id)->toBe($server->id);
});

it('oci_connection toApiConfig uses API config key names', function (): void {
    $connection = OciConnection::factory()->create([
        'team_id' => $this->team->id,
        'tenancy_ocid' => 'ocid1.tenancy.oc1..aaa',
        'user_ocid' => 'ocid1.user.oc1..bbb',
        'fingerprint' => 'aa:bb:cc',
    ]);

    $cfg = $connection->toApiConfig();

    expect($cfg)->toHaveKey('tenancy_id')
        ->and($cfg)->toHaveKey('user_id')
        ->and($cfg)->toHaveKey('key_content')
        ->and($cfg)->not->toHaveKey('tenancy_ocid')
        ->and($cfg)->not->toHaveKey('user_ocid')
        ->and($cfg)->not->toHaveKey('private_key');
});

it('cannot advance to step 2 with a connection from another team', function (): void {
    $otherTeam = Team::factory()->create();
    $connectionB = OciConnection::factory()->create(['team_id' => $otherTeam->id]);

    Livewire::test(ByOracleCloud::class)
        ->set('current_step', 1)
        ->set('selected_connection_id', $connectionB->id)
        ->call('nextStep')
        ->assertHasErrors(['selected_connection_id']);
});

it('shows validation errors on empty submit in step 2', function (): void {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $component = Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connection->id)
        ->set('private_key_id', $privateKey->id)
        ->call('createServer');

    $component->assertHasErrors([
        'compartment_ocid',
        'vcn_ocid',
        'subnet_ocid',
        'availability_domain',
        'image_id',
    ]);
});

it('does not create a server or stack when the team server limit is reached', function (): void {
    Queue::fake();
    config(['constants.coolify.self_hosted' => false]);
    $this->team->update(['custom_server_limit' => '0']);

    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connection->id)
        ->set('server_name', 'over-limit')
        ->set('region', 'us-ashburn-1')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->set('availability_domain', 'MGTy:SA-SAOPAULO-1-AD-1')
        ->set('vcn_ocid', 'ocid1.vcn.oc1..example')
        ->set('subnet_ocid', 'ocid1.subnet.oc1..example')
        ->set('shape', 'VM.Standard.A1.Flex')
        ->set('image_id', 'ocid1.image.oc1..example')
        ->set('private_key_id', $privateKey->id)
        ->set('ssh_username', 'ubuntu')
        ->call('createServer');

    expect(Server::where('name', 'over-limit')->exists())->toBeFalse()
        ->and(OciStack::where('name', 'over-limit')->exists())->toBeFalse();
});
