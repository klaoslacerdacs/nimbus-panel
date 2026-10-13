<?php

use App\Jobs\OciStackFirewallJob;
use App\Livewire\Security\OciFirewallPolicies;
use App\Models\InstanceSettings;
use App\Models\OciFirewallPolicy;
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
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $vm = fn (string $name) => OciStack::factory()->create(['team_id' => $this->team->id, 'name' => $name, 'status' => 'provisioned', 'stack_ocid' => "ocid1.ormstack.{$name}",
        'firewall_rules' => [['protocol' => 'TCP', 'port_min' => 22, 'source' => '10.0.0.0/8']]]);
    $this->web1 = $vm('web-1');
    $this->web2 = $vm('web-2');
});

it('creates a shared policy, attaches it to several VMs and applies it through Terraform on each', function (): void {
    Livewire::test(OciFirewallPolicies::class)
        ->call('create')
        ->set('name', 'Public web')
        ->set('newRule', ['protocol' => 'TCP', 'port_min' => '443', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => 'HTTPS'])
        ->call('addRule')
        ->set('stackUuids', [$this->web1->uuid, $this->web2->uuid])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $policy = OciFirewallPolicy::sole();
    expect($policy->stacks()->pluck('name')->sort()->values()->all())->toBe(['web-1', 'web-2'])
        ->and(collect($this->web1->fresh()->effectiveFirewallRules())->pluck('port_min')->sort()->values()->all())->toBe([22, 443]);
    Queue::assertPushed(OciStackFirewallJob::class, 2);
});

it('re-applies every VM that gains or loses a policy', function (): void {
    $policy = OciFirewallPolicy::factory()->create(['team_id' => $this->team->id]);
    $policy->stacks()->attach([$this->web1->id, $this->web2->id]);

    Livewire::test(OciFirewallPolicies::class)
        ->call('edit', $policy->id)
        ->set('stackUuids', [$this->web1->uuid])
        ->call('save');

    expect($policy->stacks()->pluck('name')->all())->toBe(['web-1']);
    Queue::assertPushed(OciStackFirewallJob::class, fn ($job) => $job->ociStack->is($this->web2));
    Queue::assertPushed(OciStackFirewallJob::class, fn ($job) => $job->ociStack->is($this->web1));
});

it('deletes a policy and re-applies the VMs that used it', function (): void {
    $policy = OciFirewallPolicy::factory()->create(['team_id' => $this->team->id]);
    $policy->stacks()->attach([$this->web1->id]);

    Livewire::test(OciFirewallPolicies::class)->call('delete', $policy->id);

    $this->assertModelMissing($policy);
    Queue::assertPushed(OciStackFirewallJob::class, 1);
});

it('never attaches a VM of another team', function (): void {
    $foreign = OciStack::factory()->create(['team_id' => Team::factory()->create()->id, 'status' => 'provisioned', 'stack_ocid' => 'ocid1.ormstack.x']);

    Livewire::test(OciFirewallPolicies::class)
        ->call('create')
        ->set('name', 'Sneaky')
        ->set('stackUuids', [$foreign->uuid])
        ->call('save');

    expect(OciFirewallPolicy::sole()->stacks()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lets members read policies but not change them', function (): void {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    $policy = OciFirewallPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Public web']);

    Livewire::test(OciFirewallPolicies::class)
        ->assertSee('Public web')
        ->call('edit', $policy->id)
        ->set('name', 'Hacked')
        ->call('save')
        ->assertForbidden();

    expect($policy->fresh()->name)->toBe('Public web');
});

it('deduplicates rules shared by the VM and its policies', function (): void {
    $policy = OciFirewallPolicy::factory()->create(['team_id' => $this->team->id, 'rules' => [['protocol' => 'TCP', 'port_min' => 22, 'port_max' => 22, 'source' => '10.0.0.0/8']]]);
    $policy->stacks()->attach($this->web1->id);

    expect($this->web1->fresh()->effectiveFirewallRules())->toHaveCount(1);
});
