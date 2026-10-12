<?php

use App\Jobs\OciStackFirewallJob;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->stack = OciStack::factory()->create([
        'status' => 'provisioned',
        'stack_ocid' => 'ocid1.ormstack.fw',
        'nsg_ocid' => 'ocid1.networksecuritygroup.fw',
        'firewall_rules' => [['protocol' => 'TCP', 'port_min' => 3000, 'source' => '0.0.0.0/0', 'description' => 'app']],
    ]);
});

function firewallJobMock(array $outputs = ['ingress_rule_ids' => ['managed-1']]): MockInterface
{
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('updateStack')->andReturn(['stack_id' => 'ocid1.ormstack.fw'])->byDefault();
    $mock->shouldReceive('runStackJob')->andReturn(['job_id' => 'job-fw'])->byDefault();
    $mock->shouldReceive('getStackJob')->andReturn(['status' => 'SUCCEEDED'])->byDefault();
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Apply complete! Resources: 1 added']])->byDefault();
    $mock->shouldReceive('getStackOutputs')->andReturn($outputs)->byDefault();
    $mock->shouldReceive('listNsgRules')->andReturn([])->byDefault();

    return $mock;
}

it('updates the stack to the current template with the rules, then plans and applies', function (): void {
    $mock = firewallJobMock();
    $mock->shouldReceive('updateStack')->once()
        ->with(Mockery::any(), 'ocid1.ormstack.fw', base64_encode(file_get_contents(base_path('terraform/templates/environment.zip'))), Mockery::on(
            fn ($vars) => json_decode($vars['ingress_rules_json'], true)[0]['port_min'] === 3000
        ))
        ->andReturn(['stack_id' => 'ocid1.ormstack.fw']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.ormstack.fw', 'PLAN')->once()->andReturn(['job_id' => 'job-plan']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.ormstack.fw', 'APPLY')->once()->andReturn(['job_id' => 'job-apply']);
    $mock->shouldReceive('listNsgRules')->andReturn([]);
    $mock->shouldReceive('removeNsgRules')->never();

    (new OciStackFirewallJob($this->stack))->handle($mock);

    $apply = $this->stack->jobs()->where('operation', 'apply')->sole();
    expect($apply->status)->toBe('succeeded')
        ->and($apply->firewall_rules)->toBe([['protocol' => 'TCP', 'source' => '0.0.0.0/0', 'description' => 'app', 'port_min' => 3000, 'port_max' => 3000]])
        ->and($this->stack->fresh()->last_plan_summary)->toContain('Firewall rules applied by Terraform');
});

it('adopts rules created by the SDK before Terraform managed them, removing duplicates only after apply', function (): void {
    $mock = firewallJobMock(['ingress_rule_ids' => ['managed-1', 'managed-2', 'managed-3']]);
    $mock->shouldReceive('listNsgRules')->twice()->andReturn([
        ['id' => 'legacy-80', 'protocol' => '6', 'source' => '0.0.0.0/0', 'port_min' => 80, 'port_max' => 80],
        ['id' => 'legacy-443', 'protocol' => '6', 'source' => '0.0.0.0/0', 'port_min' => 443, 'port_max' => 443],
        ['id' => 'from-other-nsg', 'protocol' => '6', 'source' => 'ocid1.networksecuritygroup.oc1..peer', 'source_type' => 'NETWORK_SECURITY_GROUP'],
    ]);
    $mock->shouldReceive('removeNsgRules')->once()
        ->with(Mockery::any(), 'ocid1.networksecuritygroup.fw', ['legacy-80', 'legacy-443'], $this->stack->region)
        ->andReturn(['removed' => 2]);

    (new OciStackFirewallJob($this->stack))->handle($mock);
});

it('leaves rules alone once the stack is already Terraform-managed', function (): void {
    OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'operation' => 'apply', 'status' => 'succeeded', 'firewall_rules' => []]);
    $mock = firewallJobMock();
    $mock->shouldReceive('removeNsgRules')->never();
    $mock->shouldReceive('listNsgRules')->never();

    (new OciStackFirewallJob($this->stack))->handle($mock);
});

it('stops before planning when the stack cannot be updated', function (): void {
    $mock = firewallJobMock();
    $mock->shouldReceive('updateStack')->andReturn(['error' => true, 'message' => 'Stack is locked']);
    $mock->shouldReceive('runStackJob')->never();

    (new OciStackFirewallJob($this->stack))->handle($mock);

    expect($this->stack->fresh()->last_plan_summary)->toContain('Stack is locked');
});

it('never touches stacks that are not provisioned', function (): void {
    $this->stack->update(['status' => 'destroyed']);
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldNotReceive('updateStack');

    (new OciStackFirewallJob($this->stack))->handle($mock);
});

it('aborts before apply when the plan would destroy or replace anything', function (string $planLog): void {
    $mock = firewallJobMock();
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.ormstack.fw', 'PLAN')->once()->andReturn(['job_id' => 'job-plan']);
    $mock->shouldReceive('getJobLogs')->with(Mockery::any(), 'job-plan', 2000)->andReturn(['lines' => explode("\n", $planLog)]);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.ormstack.fw', 'APPLY')->never();
    $mock->shouldReceive('listNsgRules')->andReturn([]);

    (new OciStackFirewallJob($this->stack))->handle($mock);

    expect($this->stack->fresh()->last_plan_summary)->toContain('apply was not run');
})->with([
    'destroy' => ['Plan: 1 to add, 0 to change, 1 to destroy.'],
    'replace' => ["# oci_core_instance.server must be replaced\nPlan: 1 to add, 0 to change, 1 to destroy."],
]);

it('keeps rules that existed on the NSG before Terraform managed it', function (): void {
    $mock = firewallJobMock(['ingress_rule_ids' => ['managed-1', 'managed-2']]);
    $mock->shouldReceive('listNsgRules')->andReturn([
        ['id' => 'legacy-22', 'protocol' => '6', 'source' => '0.0.0.0/0', 'port_min' => 22, 'port_max' => 22, 'description' => 'SSH'],
    ]);
    $mock->shouldReceive('updateStack')->once()
        ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::on(fn ($vars) => collect(json_decode($vars['ingress_rules_json'], true))->pluck('port_min')->sort()->values()->all() === [22, 3000]))
        ->andReturn(['stack_id' => 'ocid1.ormstack.fw']);
    $mock->shouldReceive('removeNsgRules')->once()->with(Mockery::any(), Mockery::any(), ['legacy-22'], Mockery::any())->andReturn([]);

    (new OciStackFirewallJob($this->stack))->handle($mock);

    expect(collect($this->stack->fresh()->firewall_rules)->pluck('port_min')->sort()->values()->all())->toBe([22, 3000]);
});

it('re-queues itself when the desired rules changed while it was applying', function (): void {
    Queue::fake();
    OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'operation' => 'apply', 'status' => 'succeeded', 'firewall_rules' => []]);
    $mock = firewallJobMock();
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), Mockery::any(), 'APPLY')->andReturnUsing(function () {
        // Someone saves a new rule while Terraform applies the previous version.
        $this->stack->update(['firewall_rules' => [...$this->stack->firewall_rules, ['protocol' => 'TCP', 'port_min' => 9000, 'source' => '0.0.0.0/0']]]);

        return ['job_id' => 'job-apply'];
    });

    (new OciStackFirewallJob($this->stack))->handle($mock);

    Queue::assertPushed(OciStackFirewallJob::class, 1);
});

it('refuses more rules than an OCI security group allows', function (): void {
    Queue::fake();
    $rules = collect(range(1, 121))->map(fn ($port) => ['protocol' => 'TCP', 'port_min' => 1000 + $port, 'source' => '0.0.0.0/0'])->all();

    expect($this->stack->queueFirewallChange($rules))->toBe('too_many_rules');
    Queue::assertNothingPushed();
});

it('marks a change as pending until the job starts, so concurrent changes are refused', function (): void {
    Queue::fake();

    expect($this->stack->queueFirewallChange([]))->toBe('queued')
        ->and($this->stack->queueFirewallChange([['protocol' => 'TCP', 'port_min' => 1, 'source' => '0.0.0.0/0']]))->toBe('busy');
});

it('keeps security groups attached in the OCI console when Terraform takes over the VNIC', function (): void {
    $this->stack->update(['oci_instance_id' => 'ocid1.instance.fw', 'compartment_ocid' => 'ocid1.compartment.fw', 'attached_nsg_ids' => null]);
    $mock = firewallJobMock();
    $mock->shouldReceive('getInstanceFirewall')->once()->andReturn(['nsg_ids' => ['ocid1.networksecuritygroup.fw', 'ocid1.networksecuritygroup.console']]);
    $mock->shouldReceive('updateStack')->once()
        ->with(Mockery::any(), Mockery::any(), Mockery::any(), Mockery::on(fn ($vars) => json_decode($vars['extra_nsg_ids_json'], true) === ['ocid1.networksecuritygroup.console']))
        ->andReturn(['stack_id' => 'ocid1.ormstack.fw']);

    (new OciStackFirewallJob($this->stack))->handle($mock);

    expect($this->stack->fresh()->attached_nsg_ids)->toBe(['ocid1.networksecuritygroup.console']);
});

it('does not apply when the attached security groups cannot be read', function (): void {
    $this->stack->update(['oci_instance_id' => 'ocid1.instance.fw', 'compartment_ocid' => 'ocid1.compartment.fw', 'attached_nsg_ids' => null]);
    $mock = firewallJobMock();
    $mock->shouldReceive('getInstanceFirewall')->andReturn(['error' => true, 'message' => 'timeout']);
    $mock->shouldReceive('updateStack')->never();

    (new OciStackFirewallJob($this->stack))->handle($mock);

    expect($this->stack->fresh()->last_plan_summary)->toContain('could detach them');
});
