<?php

use App\Livewire\Security\OciStackForm;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('can render form', function () {
    $component = Livewire::test(OciStackForm::class);

    $component->assertStatus(200);
});

it('can create stack', function () {
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(OciStackForm::class)
        ->set('name', 'Test Stack')
        ->set('oci_connection_id', $connection->id)
        ->set('config_source', 'template_zip')
        ->set('config_url', 'https://example.com/config.zip')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->set('region', 'us-ashburn-1')
        ->call('createStack')
        ->assertDispatched('stackCreated');

    $this->assertDatabaseHas('oci_stacks', [
        'name' => 'Test Stack',
        'oci_connection_id' => $connection->id,
        'config_source' => 'template_zip',
        'status' => 'pending',
    ]);
});

it('shows validation errors', function () {
    $component = Livewire::test(OciStackForm::class)
        ->set('name', '')
        ->set('oci_connection_id', 0)
        ->call('createStack');

    $component->assertHasErrors(['name', 'oci_connection_id']);
});

it('rejects a connection that belongs to another team', function () {
    $foreign = OciConnection::factory()->create(['team_id' => Team::factory()->create()->id]);

    Livewire::test(OciStackForm::class)
        ->set('name', 'Stack')
        ->set('oci_connection_id', $foreign->id)
        ->set('config_source', 'template_zip')
        ->set('config_url', 'https://example.com/config.zip')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->call('createStack')
        ->assertHasErrors(['oci_connection_id']);

    $this->assertDatabaseMissing('oci_stacks', ['oci_connection_id' => $foreign->id]);
});

it('forbids members without admin role from creating stacks', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    $connection = OciConnection::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(OciStackForm::class)
        ->set('name', 'Stack')
        ->set('oci_connection_id', $connection->id)
        ->set('config_source', 'template_zip')
        ->set('config_url', 'https://example.com/config.zip')
        ->set('compartment_ocid', 'ocid1.compartment.oc1..example')
        ->call('createStack')
        ->assertForbidden();

    $this->assertDatabaseCount('oci_stacks', 0);
});
