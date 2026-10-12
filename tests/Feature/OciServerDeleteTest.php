<?php

use App\Jobs\OciStackDestroyJob;
use App\Livewire\Server\Delete;
use App\Models\InstanceSettings;
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

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.50',
        'oci_connection_id' => $connection->id,
        'oci_instance_id' => 'ocid1.instance.oc1..vm',
    ]);
    $this->stack = OciStack::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => $connection->id,
        'server_id' => $this->server->id,
        'stack_ocid' => 'ocid1.ormstack.oc1..s',
        'status' => 'provisioned',
    ]);
});

afterEach(fn () => Server::flushIdentityMap());

it('keeps OCI resources when the destroy option is not selected', function () {
    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])
        ->call('delete', 'password', ['force_delete_resources']);

    Queue::assertNotPushed(OciStackDestroyJob::class);
    expect($this->stack->fresh()->server_id)->toBeNull();
});

it('destroys the OCI stack only when explicitly selected', function () {
    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])
        ->call('delete', 'password', ['delete_from_oci']);

    Queue::assertPushed(OciStackDestroyJob::class, fn ($job) => $job->ociStack->is($this->stack) && $job->queue === 'oci');
});

it('does not destroy OCI resources when a server is deleted outside the UI flow', function () {
    $this->server->delete();

    Queue::assertNotPushed(OciStackDestroyJob::class);
    expect($this->stack->fresh()->server_id)->toBeNull();
});

it('offers the OCI destroy option only for servers backed by a stack', function () {
    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])->assertSee('Also destroy OCI stack');

    $this->stack->delete();
    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])->assertDontSee('Also destroy OCI stack');
});
