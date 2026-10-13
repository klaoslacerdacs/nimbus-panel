<?php

use App\Jobs\OciStackDestroyJob;
use App\Livewire\Security\OciStacks;
use App\Models\InstanceSettings;
use App\Models\OciStack;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
});

it('shows how a stack was removed: destroyed on OCI or destroy failed', function (): void {
    OciStack::factory()->create(['team_id' => $this->team->id, 'name' => 'gone-vm', 'status' => 'destroyed', 'stack_ocid' => 'ocid1.stack.a', 'last_plan_summary' => 'Terraform destroy succeeded. OCI resources removed.']);
    OciStack::factory()->create(['team_id' => $this->team->id, 'name' => 'stuck-vm', 'status' => 'destroy_failed', 'stack_ocid' => 'ocid1.stack.b', 'last_plan_summary' => 'Destroy job ended with status: failed']);

    Livewire::test(OciStacks::class)
        ->assertSee('gone-vm')->assertSee('Destroyed on OCI')->assertSee('OCI resources removed')->assertSee('Remove record')
        ->assertSee('stuck-vm')->assertSee('Destroy failed')->assertSee('Retry destroy');
});

it('removes a destroyed stack record without running another destroy', function (): void {
    $stack = OciStack::factory()->create(['team_id' => $this->team->id, 'status' => 'destroyed', 'stack_ocid' => 'ocid1.stack.a']);

    Livewire::test(OciStacks::class)->call('deleteStack', $stack->id);

    Queue::assertNotPushed(OciStackDestroyJob::class);
    $this->assertModelMissing($stack);
});
