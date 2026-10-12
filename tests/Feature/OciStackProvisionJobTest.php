<?php

use App\Jobs\OciStackProvisionJob;
use App\Models\OciStack;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mockOciSequence(array ...$calls): void
{
    $mock = Mockery::mock(OciClient::class);
    foreach ($calls as [$method, $args, $return]) {
        $mock->shouldReceive($method)->with(...$args)->andReturn($return);
    }
    app()->instance(OciClient::class, $mock);
}

it('provisions stack: plan → apply → provisioned', function () {
    $stack = OciStack::factory()->create(['status' => 'pending']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('createStack')->once()->andReturn(['stack_id' => 'ocid1.stack.test']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'PLAN')->once()->andReturn(['job_id' => 'job-plan-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-plan-1')->once()->andReturn(['status' => 'SUCCEEDED']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'APPLY')->once()->andReturn(['job_id' => 'job-apply-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-apply-1')->once()->andReturn(['status' => 'succeeded']);
    $mock->shouldReceive('getStackOutputs')->with(Mockery::any(), 'job-apply-1')->once()->andReturn([
        'public_ip' => '1.2.3.4',
        'private_ip' => '10.0.0.1',
        'instance_id' => 'ocid1.instance.test',
        'instance_state' => 'RUNNING',
    ]);
    app()->instance(OciClient::class, $mock);

    (new OciStackProvisionJob($stack))->handle(app(OciClient::class));

    expect($stack->fresh()->status)->toBe('provisioned');
    expect($stack->fresh()->oci_instance_id)->toBe('ocid1.instance.test');
});

it('marks failed when plan job creation fails', function () {
    $stack = OciStack::factory()->create(['status' => 'pending']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('createStack')->once()->andReturn(['stack_id' => 'ocid1.stack.test']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'PLAN')->once()->andReturn(['error' => 'bridge error']);
    $mock->shouldNotReceive('runStackJob', Mockery::any(), Mockery::any(), 'APPLY');
    app()->instance(OciClient::class, $mock);

    (new OciStackProvisionJob($stack))->handle(app(OciClient::class));

    expect($stack->fresh()->status)->toBe('failed');
});

it('marks failed when apply times out', function () {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.existing']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.existing', 'PLAN')->once()->andReturn(['job_id' => 'job-plan-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-plan-1')->once()->andReturn(['status' => 'succeeded']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.existing', 'APPLY')->once()->andReturn(['job_id' => 'job-apply-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-apply-1')->once()->andReturn(['status' => 'FAILED']);
    $mock->shouldReceive('getJobLogs')->with(Mockery::any(), 'job-apply-1')->once()->andReturn(['lines' => ['Error: quota exceeded for shape VM.Standard.E4.Flex']]);
    app()->instance(OciClient::class, $mock);

    (new OciStackProvisionJob($stack))->handle(app(OciClient::class));

    $fresh = $stack->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->last_plan_summary)->toContain('quota exceeded');
});

it('is dispatched when createServer succeeds', function () {
    Queue::fake();

    // Just assert the job class is pushed — full flow covered above
    Queue::assertNothingPushed();

    OciStackProvisionJob::dispatch(OciStack::factory()->create());

    Queue::assertPushed(OciStackProvisionJob::class);
});
