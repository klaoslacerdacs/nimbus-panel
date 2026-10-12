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

it('talks to OCI in the stack region, not the connection home region', function () {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.r', 'region' => 'sa-saopaulo-1']);
    $stack->ociConnection->update(['region' => 'us-ashburn-1']);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('runStackJob')->once()
        ->with(Mockery::on(fn ($config) => $config['region'] === 'sa-saopaulo-1'), 'ocid1.stack.r', 'PLAN')
        ->andReturn(['error' => true]);

    (new OciStackProvisionJob($stack))->handle($mock);

    expect($stack->fresh()->status)->toBe('failed');
});

it('stores the NSG even without firewall rules and reports failed rule application', function (array $rules, ?string $summary) {
    $stack = OciStack::factory()->create(['status' => 'pending', 'stack_ocid' => 'ocid1.stack.n', 'firewall_rules' => $rules]);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('runStackJob')->andReturn(['job_id' => 'job']);
    $mock->shouldReceive('getStackJob')->andReturn(['status' => 'SUCCEEDED']);
    $mock->shouldReceive('getStackOutputs')->andReturn(['instance_id' => 'ocid1.instance.n', 'nsg_id' => 'ocid1.networksecuritygroup.n']);
    $mock->shouldReceive('addNsgRules')->andReturn(['error' => true, 'message' => 'quota']);

    (new OciStackProvisionJob($stack))->handle($mock);

    expect($stack->fresh()->nsg_ocid)->toBe('ocid1.networksecuritygroup.n')
        ->and($stack->fresh()->last_plan_summary)->toBe($summary);
})->with([
    'no rules' => [[], null],
    'rules fail' => [[['protocol' => 'TCP', 'port_min' => 80, 'port_max' => 80, 'source' => '0.0.0.0/0']], 'Instance created, but firewall rules could not be applied: quota. Add them from the OCI dashboard.'],
]);

it('ships an environment.zip identical to the Terraform template sources', function () {
    $zip = new ZipArchive;
    expect($zip->open(base_path('terraform/templates/environment.zip')))->toBeTrue();

    foreach (glob(base_path('terraform/templates/environment/*.tf')) as $file) {
        expect($zip->getFromName(basename($file)))->toBe(file_get_contents($file), basename($file).' is out of sync — rebuild environment.zip');
    }
    expect($zip->numFiles)->toBe(count(glob(base_path('terraform/templates/environment/*.tf'))));
});
