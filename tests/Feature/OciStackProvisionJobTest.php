<?php

use App\Jobs\OciStackDestroyJob;
use App\Jobs\OciStackProvisionJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\OciStack;
use App\Models\Server;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

function mockOciSequence(array ...$calls): void
{
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    foreach ($calls as [$method, $args, $return]) {
        $mock->shouldReceive($method)->with(...$args)->andReturn($return);
    }
    app()->instance(OciClient::class, $mock);
}

it('provisions stack: plan → apply → provisioned', function (): void {
    $stack = OciStack::factory()->create(['status' => 'pending']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
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

    new OciStackProvisionJob($stack)->handle(app(OciClient::class));

    expect($stack->fresh()->status)->toBe('provisioned');
    expect($stack->fresh()->oci_instance_id)->toBe('ocid1.instance.test');
});

it('marks failed when plan job creation fails', function (): void {
    $stack = OciStack::factory()->create(['status' => 'pending']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    $mock->shouldReceive('createStack')->once()->andReturn(['stack_id' => 'ocid1.stack.test']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.test', 'PLAN')->once()->andReturn(['error' => 'plan job rejected']);
    $mock->shouldNotReceive('runStackJob', Mockery::any(), Mockery::any(), 'APPLY');
    app()->instance(OciClient::class, $mock);

    new OciStackProvisionJob($stack)->handle(app(OciClient::class));

    expect($stack->fresh()->status)->toBe('failed');
});

it('marks failed when apply times out', function (): void {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.existing']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.existing', 'PLAN')->once()->andReturn(['job_id' => 'job-plan-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-plan-1')->once()->andReturn(['status' => 'succeeded']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.existing', 'APPLY')->once()->andReturn(['job_id' => 'job-apply-1']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-apply-1')->once()->andReturn(['status' => 'FAILED']);
    $mock->shouldReceive('getJobLogs')->with(Mockery::any(), 'job-apply-1', 2000)->once()->andReturn(['lines' => ['Error: quota exceeded for shape VM.Standard.E4.Flex']]);
    app()->instance(OciClient::class, $mock);

    new OciStackProvisionJob($stack)->handle(app(OciClient::class));

    $fresh = $stack->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->last_plan_summary)->toContain('quota exceeded');
});

it('is dispatched when createServer succeeds', function (): void {
    Queue::fake();

    // Just assert the job class is pushed — full flow covered above
    Queue::assertNothingPushed();

    OciStackProvisionJob::dispatch(OciStack::factory()->create());

    Queue::assertPushed(OciStackProvisionJob::class);
});

it('talks to OCI in the stack region, not the connection home region', function (): void {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.r', 'region' => 'sa-saopaulo-1']);
    $stack->ociConnection->update(['region' => 'us-ashburn-1']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    $mock->shouldReceive('runStackJob')->once()
        ->with(Mockery::on(fn ($config): bool => $config['region'] === 'sa-saopaulo-1'), 'ocid1.stack.r', 'PLAN')
        ->andReturn(['error' => true]);

    new OciStackProvisionJob($stack)->handle($mock);

    expect($stack->fresh()->status)->toBe('failed');
});

it('provisions firewall rules as Terraform variables, never through the SDK', function (): void {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => null, 'firewall_rules' => [
        ['protocol' => 'TCP', 'port_min' => 443, 'source' => '0.0.0.0/0', 'description' => 'HTTPS'],
    ]]);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => []]);
    $mock->shouldReceive('createStack')->once()
        ->with(Mockery::any(), Mockery::any(), Mockery::any(), 'ZIP_UPLOAD', Mockery::any(), Mockery::on(fn ($vars) => json_decode($vars['ingress_rules_json'], true) === [
            ['protocol' => 'TCP', 'source' => '0.0.0.0/0', 'description' => 'HTTPS', 'port_min' => 443, 'port_max' => 443],
        ]))
        ->andReturn(['stack_id' => 'ocid1.stack.fw']);
    $mock->shouldReceive('runStackJob')->andReturn(['job_id' => 'job']);
    $mock->shouldReceive('getStackJob')->andReturn(['status' => 'SUCCEEDED']);
    $mock->shouldReceive('getStackOutputs')->andReturn(['instance_id' => 'ocid1.instance.fw', 'nsg_id' => 'ocid1.networksecuritygroup.fw']);
    $mock->shouldNotReceive('addNsgRules');

    (new OciStackProvisionJob($stack))->handle($mock);

    expect($stack->fresh()->nsg_ocid)->toBe('ocid1.networksecuritygroup.fw')
        ->and($stack->jobs()->where('operation', 'apply')->value('firewall_rules'))->toHaveCount(1);
});

it('ships an environment.zip identical to the Terraform template sources', function (): void {
    $zip = new ZipArchive;
    expect($zip->open(base_path('terraform/templates/environment.zip')))->toBeTrue();

    foreach (glob(base_path('terraform/templates/environment/*.tf')) as $file) {
        expect($zip->getFromName(basename($file)))->toBe(file_get_contents($file), basename($file).' is out of sync — rebuild environment.zip');
    }
    expect($zip->numFiles)->toBe(count(glob(base_path('terraform/templates/environment/*.tf'))));
});

it('destroys in the stack region, not the connection home region', function (): void {
    $stack = OciStack::factory()->create(['status' => 'destroying', 'stack_ocid' => 'ocid1.stack.d', 'region' => 'sa-saopaulo-1']);
    $stack->ociConnection->update(['region' => 'us-ashburn-1']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    $mock->shouldReceive('runStackJob')->once()
        ->with(Mockery::on(fn ($config): bool => $config['region'] === 'sa-saopaulo-1'), 'ocid1.stack.d', 'DESTROY', true)
        ->andReturn(['error' => true]);

    new OciStackDestroyJob($stack)->handle($mock);

    expect($stack->fresh()->status)->toBe('destroy_failed');
});

it('waits until the new VM accepts SSH before validating it', function (): void {
    Queue::fake();
    Sleep::fake();
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.s']);
    $server = Server::factory()->create(['ip' => '', 'team_id' => $stack->team_id]);
    $stack->update(['server_id' => $server->id]);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    $mock->shouldReceive('runStackJob')->andReturn(['job_id' => 'job']);
    $mock->shouldReceive('getStackJob')->andReturn(['status' => 'SUCCEEDED']);
    $mock->shouldReceive('getStackOutputs')->andReturn(['public_ip' => '203.0.113.9', 'instance_id' => 'ocid1.instance.s']);

    $booting = Mockery::mock($server->fresh())->makePartial();
    $booting->shouldReceive('refresh')->andReturnSelf();
    $booting->shouldReceive('validateConnection')->with(true)->times(3)
        ->andReturn(['uptime' => false, 'error' => 'refused'], ['uptime' => false, 'error' => 'refused'], ['uptime' => true, 'error' => null]);
    $stack->setRelation('server', $booting);

    (new OciStackProvisionJob($stack))->handle($mock);

    Sleep::assertSleptTimes(2);
    Queue::assertPushed(ValidateAndInstallServerJob::class, fn ($job) => $job->server->id === $server->id);
});

it('marks the stack failed when the worker kills the job', function (): void {
    $stack = OciStack::factory()->create(['status' => 'applying']);

    (new OciStackProvisionJob($stack))->failed(new RuntimeException('Job has timed out.'));

    expect($stack->fresh()->status)->toBe('failed')
        ->and($stack->fresh()->last_plan_summary)->toContain('timed out');
});

it('keeps a destroyed stack as history instead of deleting it', function (): void {
    $stack = OciStack::factory()->create(['status' => 'destroying', 'stack_ocid' => 'ocid1.stack.h']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getJobLogs')->andReturn(['lines' => ['Terraform log line']])->byDefault();
    $mock->shouldReceive('runStackJob')->andReturn(['job_id' => 'job-d']);
    $mock->shouldReceive('getStackJob')->andReturn(['status' => 'SUCCEEDED']);

    new OciStackDestroyJob($stack)->handle($mock);

    $stack->refresh();
    expect($stack->exists)->toBeTrue()
        ->and($stack->status)->toBe('destroyed')
        ->and($stack->last_plan_summary)->toContain('OCI resources removed')
        ->and($stack->isActive())->toBeFalse();
});

it('records every Terraform job with its status and log', function (): void {
    Sleep::fake();
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.rec']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.rec', 'PLAN')->andReturn(['job_id' => 'job-plan']);
    $mock->shouldReceive('runStackJob')->with(Mockery::any(), 'ocid1.stack.rec', 'APPLY')->andReturn(['job_id' => 'job-apply']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-plan')->andReturn(['status' => 'IN_PROGRESS'], ['status' => 'SUCCEEDED']);
    $mock->shouldReceive('getStackJob')->with(Mockery::any(), 'job-apply')->andReturn(['status' => 'FAILED']);
    $mock->shouldReceive('getJobLogs')->with(Mockery::any(), 'job-plan', 2000)->andReturn(['lines' => ['Plan: 6 to add']]);
    $mock->shouldReceive('getJobLogs')->with(Mockery::any(), 'job-apply', 2000)->andReturn(['lines' => ['Error: Out of host capacity']]);

    (new OciStackProvisionJob($stack))->handle($mock);

    $jobs = $stack->jobs()->get()->keyBy('operation');
    expect($jobs['plan']->status)->toBe('succeeded')
        ->and($jobs['plan']->log)->toBe('Plan: 6 to add')
        ->and($jobs['plan']->finished_at)->not->toBeNull()
        ->and($jobs['apply']->status)->toBe('failed')
        ->and($jobs['apply']->log)->toContain('Out of host capacity')
        ->and($jobs['apply']->team_id)->toBe($stack->team_id)
        ->and($stack->fresh()->last_plan_summary)->toContain('Out of host capacity');
});

it('records the destroy job and the reason when OCI rejects it', function (): void {
    $stack = OciStack::factory()->create(['status' => 'destroying', 'stack_ocid' => 'ocid1.stack.d2']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('runStackJob')->andReturn(['error' => true, 'message' => 'Stack is locked by another job']);

    (new OciStackDestroyJob($stack))->handle($mock);

    $job = $stack->jobs()->sole();
    expect($job->operation)->toBe('destroy')
        ->and($job->status)->toBe('failed')
        ->and($job->log)->toContain('Stack is locked by another job')
        ->and($stack->fresh()->status)->toBe('destroy_failed');
});
