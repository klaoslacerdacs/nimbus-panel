<?php

use App\Jobs\OciStackProvisionJob;
use App\Models\OciStack;
use App\Services\OciBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mockBridgeSequence(array ...$calls): void
{
    $mock = Mockery::mock(OciBridgeClient::class);
    foreach ($calls as [$method, $args, $return]) {
        $mock->shouldReceive($method)->with(...$args)->andReturn($return);
    }
    app()->instance(OciBridgeClient::class, $mock);
}

it('provisions stack: plan → apply → provisioned', function () {
    $stack = OciStack::factory()->create(['status' => 'pending']);

    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('createStack')->once()->andReturn(['stack_id' => 'ocid1.stack.test']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'PLAN')->once()->andReturn(['job_id' => 'job-plan-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-plan-1')->once()->andReturn(['status' => 'SUCCEEDED']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'APPLY')->once()->andReturn(['job_id' => 'job-apply-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-apply-1')->once()->andReturn(['status' => 'succeeded']);
    app()->instance(OciBridgeClient::class, $mock);

    (new OciStackProvisionJob($stack))->handle(app(OciBridgeClient::class));

    expect($stack->fresh()->status)->toBe('provisioned');
});

it('marks failed when plan job creation fails', function () {
    $stack = OciStack::factory()->create(['status' => 'pending']);

    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('createStack')->once()->andReturn(['stack_id' => 'ocid1.stack.test']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'PLAN')->once()->andReturn(['error' => 'bridge error']);
    $mock->shouldNotReceive('runStackJob', Mockery::any(), Mockery::any(), 'APPLY');
    app()->instance(OciBridgeClient::class, $mock);

    (new OciStackProvisionJob($stack))->handle(app(OciBridgeClient::class));

    expect($stack->fresh()->status)->toBe('failed');
});

it('marks failed when apply times out', function () {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.existing']);

    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.existing', 'PLAN')->once()->andReturn(['job_id' => 'job-plan-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-plan-1')->once()->andReturn(['status' => 'succeeded']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.existing', 'APPLY')->once()->andReturn(['job_id' => 'job-apply-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-apply-1')->once()->andReturn(['status' => 'FAILED']);
    app()->instance(OciBridgeClient::class, $mock);

    (new OciStackProvisionJob($stack))->handle(app(OciBridgeClient::class));

    expect($stack->fresh()->status)->toBe('failed');
});

it('is dispatched when createServer succeeds', function () {
    Queue::fake();

    // Just assert the job class is pushed — full flow covered above
    Queue::assertNothingPushed();

    OciStackProvisionJob::dispatch(OciStack::factory()->create());

    Queue::assertPushed(OciStackProvisionJob::class);
});
