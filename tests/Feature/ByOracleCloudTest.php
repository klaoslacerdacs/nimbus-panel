<?php

use App\Jobs\OciStackProvisionJob;
use App\Livewire\Server\New\ByOracleCloud;
use App\Models\OciConnection;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('can render step 1', function () {
    $component = Livewire::test(ByOracleCloud::class);

    $component->assertStatus(200);
});

it('can advance to step 2 by selecting connection', function () {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);

    $component = Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connection->id)
        ->call('nextStep');

    $component->assertSet('current_step', 2);
});

it('createServer creates Server and OciStack records', function () {
    Queue::fake();

    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connection->id)
        ->call('nextStep')
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-server')
        ->set('region', 'us-ashburn-1')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->set('availability_domain', 'MGTy:SA-SAOPAULO-1-AD-1')
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

it('cannot advance to step 2 with a connection from another team', function () {
    $otherTeam = Team::factory()->create();
    $connectionB = OciConnection::factory()->create(['team_id' => $otherTeam->id]);

    Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connectionB->id)
        ->call('nextStep')
        ->assertHasErrors(['selected_connection_id']);
});

it('shows validation errors on empty submit in step 2', function () {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $component = Livewire::test(ByOracleCloud::class)
        ->set('selected_connection_id', $connection->id)
        ->set('private_key_id', $privateKey->id)
        ->call('createServer');

    $component->assertHasErrors([
        'compartment_ocid',
        'availability_domain',
        'subnet_ocid',
        'image_id',
    ]);
});
