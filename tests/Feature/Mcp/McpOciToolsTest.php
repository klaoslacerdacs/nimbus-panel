<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'app.maintenance.driver' => 'file']);
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->connection = OciConnection::factory()->create(['team_id' => $this->team->id, 'name' => 'prod-oci', 'region' => 'us-ashburn-1']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => $this->connection->id,
        'oci_instance_id' => 'ocid1.instance.oc1..vm',
        'oci_compartment_id' => 'ocid1.compartment.oc1..c',
        'oci_region' => 'sa-saopaulo-1',
    ]);
    $this->stack = OciStack::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => $this->connection->id,
        'server_id' => $this->server->id,
        'status' => 'failed',
        'region' => 'sa-saopaulo-1',
        'last_plan_summary' => "Error: 409-Conflict\nAPI_KEY=supersecretvalue",
    ]);
});

function mcpOciCall(string $name, array $arguments = [], array $abilities = ['read'])
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-oci', $abilities)->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => (object) $arguments],
    ]);
}

function mcpOciText($response): string
{
    return (string) data_get($response->json(), 'result.content.0.text');
}

it('lists team OCI connections without any key material', function (): void {
    OciConnection::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'other-team-oci']);

    $text = mcpOciText(mcpOciCall('list_oci_connections')->assertOk());

    expect($text)->toContain('prod-oci')->toContain('us-ashburn-1')
        ->not->toContain('other-team-oci')
        ->not->toContain('BEGIN')
        ->not->toContain('private_key')
        ->not->toContain('passphrase')
        ->not->toContain('fingerprint');
});

it('lists provisioning stacks with a redacted Terraform summary', function (): void {
    $text = mcpOciCall('list_oci_stacks', ['status' => 'failed'])->assertOk();
    $text = mcpOciText($text);

    expect($text)->toContain('409-Conflict')->toContain($this->server->uuid)
        ->not->toContain('supersecretvalue');
});

it('shows the live OCI state and metrics of a team server', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getInstanceStatus')->once()
        ->with(Mockery::on(fn ($config) => $config['region'] === 'sa-saopaulo-1'), 'ocid1.instance.oc1..vm', 'sa-saopaulo-1')
        ->andReturn(['id' => 'ocid1.instance.oc1..vm', 'lifecycle_state' => 'RUNNING', 'shape' => 'VM.Standard.A1.Flex']);
    $mock->shouldReceive('getInstanceMetrics')->once()->andReturn(['cpu_percent' => 3.5, 'memory_percent' => 12.0, 'net_bytes_in' => null, 'net_bytes_out' => null]);
    app()->instance(OciClient::class, $mock);

    $text = mcpOciText(mcpOciCall('get_oci_server', ['uuid' => $this->server->uuid])->assertOk());

    expect($text)->toContain('RUNNING')->toContain('VM.Standard.A1.Flex')->toContain('3.5')->toContain('failed')
        ->not->toContain('"oci_connection_id"');
});

it('does not expose servers of another team', function (): void {
    $foreign = Server::factory()->create(['team_id' => Team::factory()->create()->id, 'oci_instance_id' => 'ocid1.instance.oc1..x']);
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldNotReceive('getInstanceStatus');
    $mock->shouldNotReceive('instanceAction');
    app()->instance(OciClient::class, $mock);

    expect(mcpOciText(mcpOciCall('get_oci_server', ['uuid' => $foreign->uuid])))->toContain('not found');
    expect(mcpOciText(mcpOciCall('oci_instance_action', ['uuid' => $foreign->uuid, 'action' => 'start'], ['read', 'deploy'])))->toContain('not found');
});

it('requires the deploy ability for power actions', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldNotReceive('instanceAction');
    app()->instance(OciClient::class, $mock);

    $text = mcpOciText(mcpOciCall('oci_instance_action', ['uuid' => $this->server->uuid, 'action' => 'start']));

    expect($text)->toContain('deploy');
});

it('requires confirm=true to stop or reboot a VM', function (string $action): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldNotReceive('instanceAction');
    app()->instance(OciClient::class, $mock);

    expect(mcpOciText(mcpOciCall('oci_instance_action', ['uuid' => $this->server->uuid, 'action' => $action], ['read', 'deploy'])))
        ->toContain('confirm=true');
})->with(['stop', 'reboot']);

it('runs graceful power actions in the server region and audits them', function (string $action, string $ociAction): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('instanceAction')->once()
        ->with(Mockery::on(fn ($config) => $config['region'] === 'sa-saopaulo-1'), 'ocid1.instance.oc1..vm', 'sa-saopaulo-1', $ociAction)
        ->andReturn(['lifecycle_state' => 'STOPPING']);
    app()->instance(OciClient::class, $mock);

    $text = mcpOciText(mcpOciCall('oci_instance_action', ['uuid' => $this->server->uuid, 'action' => $action, 'confirm' => true], ['read', 'deploy']));

    expect($text)->toContain('STOPPING');
    expect(AuditEvent::query()->where('event', 'mcp.oci_instance_action')->exists())->toBeTrue();
})->with([['start', 'START'], ['stop', 'SOFTSTOP'], ['reboot', 'SOFTRESET']]);

it('lists the OCI tools in the help catalog', function (): void {
    expect(mcpOciText(mcpOciCall('coolify_help', ['intent' => 'oci'])))
        ->toContain('get_oci_server')->toContain('oci_instance_action');
});
