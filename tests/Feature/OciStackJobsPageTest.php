<?php

use App\Livewire\Security\OciStackJobs;
use App\Models\InstanceSettings;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->stack = OciStack::factory()->create(['team_id' => $this->team->id, 'name' => 'web-vm', 'region' => 'sa-saopaulo-1']);
    $this->destroy = OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'operation' => 'destroy', 'status' => 'succeeded', 'log' => 'Destroy complete! Resources: 6 destroyed.']);
    $this->apply = OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'operation' => 'apply', 'status' => 'failed', 'log' => 'Error: Out of host capacity']);
});

it('lists the team Terraform jobs and opens a log', function (): void {
    $foreignStack = OciStack::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'other-team-vm']);
    OciStackJob::factory()->create(['oci_stack_id' => $foreignStack->id]);

    Livewire::test(OciStackJobs::class)
        ->assertSee('web-vm')->assertSee('Destroy')->assertSee('Succeeded')->assertSee('Apply')->assertSee('Failed')
        ->assertDontSee('other-team-vm')
        ->call('selectJob', $this->destroy->id)
        ->assertSee('Destroy complete! Resources: 6 destroyed.');
});

it('filters jobs by operation and stack', function (): void {
    $otherStack = OciStack::factory()->create(['team_id' => $this->team->id, 'name' => 'db-vm']);
    OciStackJob::factory()->create(['oci_stack_id' => $otherStack->id, 'operation' => 'destroy']);

    Livewire::test(OciStackJobs::class)
        ->set('operation', 'destroy')
        ->assertViewHas('jobs', fn ($jobs) => $jobs->pluck('operation')->unique()->all() === ['destroy'] && $jobs->count() === 2)
        ->set('stack', $this->stack->uuid)
        ->assertViewHas('jobs', fn ($jobs) => $jobs->count() === 1 && $jobs->first()->is($this->destroy));
});

it('never opens a job of another team', function (): void {
    $foreign = OciStackJob::factory()->create(['oci_stack_id' => OciStack::factory()->create(['team_id' => Team::factory()->create()->id])->id, 'log' => 'secret infra log']);

    Livewire::test(OciStackJobs::class)
        ->call('selectJob', $foreign->id)
        ->assertDontSee('secret infra log');
});

it('refreshes the log of a running job live from OCI', function (): void {
    $running = OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'operation' => 'destroy', 'status' => 'in_progress', 'log' => null, 'finished_at' => null]);
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->once()
        ->with(Mockery::on(fn ($config) => $config['region'] === 'sa-saopaulo-1'), $running->job_ocid, 2000)
        ->andReturn(['lines' => ['oci_core_instance.server: Destroying...']]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciStackJobs::class)
        ->call('selectJob', $running->id)
        ->call('refreshLiveLog')
        ->assertSee('oci_core_instance.server: Destroying...');

    expect($running->fresh()->log)->toContain('Destroying...');
});
