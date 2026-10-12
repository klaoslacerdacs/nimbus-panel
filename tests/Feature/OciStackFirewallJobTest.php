<?php

use App\Jobs\OciStackFirewallJob;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('adopts rules created by the SDK before Terraform managed them, removing them only after apply', function (): void {
    $mock = firewallJobMock(['ingress_rule_ids' => ['managed-1']]);
    $mock->shouldReceive('listNsgRules')->once()->andReturn([['id' => 'managed-1'], ['id' => 'legacy-80'], ['id' => 'legacy-443']]);
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
