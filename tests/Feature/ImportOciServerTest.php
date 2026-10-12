<?php

use App\Livewire\Server\New\ImportOciServer;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function mockImportClient(array $overrides = []): MockInterface
{
    $defaults = [
        'listRegions' => [['name' => 'sa-saopaulo-1'], ['name' => 'us-ashburn-1']],
        'listCompartments' => [['id' => 'ocid1.compartment.oc1..c1', 'name' => 'root', 'description' => null]],
        'listInstances' => [
            ['id' => 'ocid1.instance.oc1..running', 'display_name' => 'web-1', 'lifecycle_state' => 'RUNNING', 'shape' => 'VM.Standard.A1.Flex', 'region' => 'sa-saopaulo-1'],
            ['id' => 'ocid1.instance.oc1..dead', 'display_name' => 'zombie-vm', 'lifecycle_state' => 'TERMINATED', 'shape' => 'VM.Standard.E2.1', 'region' => 'sa-saopaulo-1'],
        ],
        'getInstancePrimaryVnic' => ['public_ip' => '203.0.113.10', 'private_ip' => '10.0.0.5'],
    ];

    $mock = Mockery::mock(OciClient::class);
    foreach (array_merge($defaults, $overrides) as $method => $returnValue) {
        $mock->shouldReceive($method)->andReturn($returnValue);
    }

    return $mock;
}

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id, 'is_git_related' => false]);

    app()->instance(OciClient::class, mockImportClient());
});

function driveToStep3()
{
    return Livewire::test(ImportOciServer::class)
        ->set('oci_connection_id', test()->connection->id)
        ->set('compartment_id', 'ocid1.compartment.oc1..c1')
        ->call('nextStep') // step 1 -> 2, loads instances
        ->call('selectInstance', 'ocid1.instance.oc1..running')
        ->call('nextStep') // step 2 -> 3
        ->set('private_key_id', test()->privateKey->id);
}

it('imports an existing OCI instance as a server without creating an OciStack', function () {
    driveToStep3()
        ->call('createServer')
        ->assertHasNoErrors();

    $server = Server::where('oci_instance_id', 'ocid1.instance.oc1..running')->first();
    expect($server)->not->toBeNull();
    expect($server->ip)->toBe('203.0.113.10');
    expect($server->oci_region)->toBe('sa-saopaulo-1');
    expect($server->oci_compartment_id)->toBe('ocid1.compartment.oc1..c1');
    expect($server->oci_connection_id)->toBe($this->connection->id);
    expect($server->oci_instance_status)->toBe('RUNNING');
    expect($server->oci_metrics_source)->toBe('oci_monitoring');
    expect($server->user)->toBe('ubuntu');

    expect($server->ociStack)->toBeNull();
    expect(OciStack::count())->toBe(0);
});

it('filters out terminated instances from the selection list', function () {
    Livewire::test(ImportOciServer::class)
        ->set('oci_connection_id', $this->connection->id)
        ->set('compartment_id', 'ocid1.compartment.oc1..c1')
        ->call('nextStep')
        ->assertSee('web-1')
        ->assertDontSee('zombie-vm');
});

it('rejects importing an instance that is already imported', function () {
    Server::factory()->create([
        'team_id' => $this->team->id,
        'oci_instance_id' => 'ocid1.instance.oc1..running',
    ]);

    driveToStep3()
        ->call('createServer')
        ->assertDispatched('error');

    expect(Server::where('oci_instance_id', 'ocid1.instance.oc1..running')->count())->toBe(1);
});

it('rejects import when the server limit is reached', function () {
    config(['constants.coolify.self_hosted' => false]);
    $this->team->update(['custom_server_limit' => '0']);

    driveToStep3()
        ->call('createServer')
        ->assertDispatched('error');

    expect(Server::where('oci_instance_id', 'ocid1.instance.oc1..running')->exists())->toBeFalse();
});

it('rejects an instance OCID that was not listed for the selected compartment', function () {
    Livewire::test(ImportOciServer::class)
        ->set('oci_connection_id', $this->connection->id)
        ->set('compartment_id', 'ocid1.compartment.oc1..c1')
        ->call('nextStep')
        ->set('oci_instance_id', 'ocid1.instance.oc1..someone-else')
        ->call('nextStep')
        ->assertHasErrors(['oci_instance_id']);
});

it('rejects a compartment that was not listed for the connection', function () {
    Livewire::test(ImportOciServer::class)
        ->set('oci_connection_id', $this->connection->id)
        ->set('compartment_id', 'ocid1.compartment.oc1..unlisted')
        ->call('nextStep')
        ->assertHasErrors(['compartment_id']);
});

it('rejects an invalid server IP', function () {
    driveToStep3()
        ->set('ip', 'not an ip; rm -rf /')
        ->call('createServer')
        ->assertHasErrors(['ip']);

    expect(Server::where('oci_instance_id', 'ocid1.instance.oc1..running')->exists())->toBeFalse();
});

it('cannot tamper with the listed instances from the browser', function () {
    Livewire::test(ImportOciServer::class)
        ->set('instances', [['id' => 'ocid1.instance.oc1..forged']]);
})->throws(CannotUpdateLockedPropertyException::class);
