<?php

use App\Livewire\Security\OciStackShow;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->connection = OciConnection::factory()->create(['team_id' => $this->team->id]);
    $this->stack = OciStack::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => $this->connection->id,
        'status' => 'provisioned',
        'stack_ocid' => 'ocid1.ormstack.oc1..example',
        'compartment_ocid' => 'ocid1.compartment.oc1..example',
        'tf_vars' => ['region' => 'sa-saopaulo-1', 'shape' => 'VM.Standard.A1.Flex'],
    ]);
});

it('renders provisioned stack details', function (): void {
    Livewire::test(OciStackShow::class, ['stack' => $this->stack])
        ->assertSee($this->stack->name)
        ->assertSee('Provisioned')
        ->assertSee('sa-saopaulo-1')
        ->assertSee('ocid1.ormstack.oc1..example')
        ->assertSee('OCI Console');
});

it('shows tf_vars table excluding ssh key', function (): void {
    $this->stack->update(['tf_vars' => [
        'region' => 'sa-saopaulo-1',
        'shape' => 'VM.Standard.A1.Flex',
        'ssh_authorized_keys' => 'ssh-rsa AAAA...',
    ]]);

    Livewire::test(OciStackShow::class, ['stack' => $this->stack])
        ->assertSee('shape')
        ->assertSee('VM.Standard.A1.Flex')
        ->assertDontSee('ssh-rsa AAAA...')
        ->assertSee('hidden');
});

it('shows error message on failed stack', function (): void {
    $this->stack->update(['status' => 'failed', 'last_plan_summary' => 'Quota exceeded for VM.Standard.A1.Flex']);

    Livewire::test(OciStackShow::class, ['stack' => $this->stack])
        ->assertSee('Failed')
        ->assertSee('Quota exceeded for VM.Standard.A1.Flex');
});

it('denies access to other team members', function (): void {
    $this->withoutVite();
    $other = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($other, ['role' => 'owner']);
    $this->actingAs($other);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(OciStackShow::class, ['stack' => $this->stack])
        ->assertForbidden();
});
