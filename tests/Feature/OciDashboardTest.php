<?php

use App\Jobs\OciStackFirewallJob;
use App\Livewire\Server\OciDashboard;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\OciFirewallPolicy;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Build a mock OciClient with safe defaults for all load methods.
 * Pass an array of method => value pairs to override specific return values.
 */
function ociFirewall(array $nsgIds = ['ocid1.networksecuritygroup.oc1..example']): array
{
    return [
        'vnic_id' => 'ocid1.vnic.oc1..v',
        'vcn_id' => 'ocid1.vcn.oc1..n',
        'compartment_id' => 'ocid1.compartment.oc1..example',
        'nsg_ids' => $nsgIds,
        'nsgs' => array_map(fn ($id) => ['id' => $id, 'display_name' => 'web-nsg'], $nsgIds),
        'security_lists' => [[
            'id' => 'ocid1.securitylist.oc1..s',
            'display_name' => 'Default Security List',
            'rules' => [['protocol' => '6', 'protocol_label' => 'TCP', 'source' => '0.0.0.0/0', 'port_min' => 22, 'port_max' => 22]],
        ]],
    ];
}

function mockClient(array $overrides = []): MockInterface
{
    $defaults = [
        'getInstanceStatus' => ['lifecycle_state' => 'RUNNING'],
        'getInstanceMetrics' => ['cpu_percent' => null, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null],
        'listNsgRules' => [],
        'getInstanceFirewall' => ociFirewall(),
        'listVolumeAttachments' => [],
        'listLoadBalancers' => [],
        'listAutonomousDatabases' => [],
        'listRouteTables' => [],
        'listBuckets' => [],
        'listVolumes' => [],
    ];

    $mock = Mockery::mock(OciClient::class);
    // An override of `false` leaves the method unstubbed so the test can set a stricter expectation.
    foreach (array_filter(array_merge($defaults, $overrides), fn ($v): bool => $v !== false) as $method => $returnValue) {
        $mock->shouldReceive($method)->andReturn($returnValue);
    }

    return $mock;
}

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->connection = OciConnection::factory()->create(['team_id' => $this->team->id]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => $this->connection->id,
        'oci_instance_id' => 'ocid1.instance.oc1..example',
        'oci_region' => 'us-ashburn-1',
        'oci_compartment_id' => 'ocid1.compartment.oc1..example',
        'oci_metrics_source' => 'oci_monitoring',
    ]);

    $this->stack = OciStack::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => $this->connection->id,
        'server_id' => $this->server->id,
        'status' => 'provisioned',
        'nsg_ocid' => 'ocid1.networksecuritygroup.oc1..example',
        'firewall_rules' => [['protocol' => 'TCP', 'port_min' => 22, 'port_max' => 22, 'source' => '0.0.0.0/0']],
    ]);
});

it('renders without error when instance is provisioned', function (): void {
    $mock = mockClient(['getInstanceStatus' => ['lifecycle_state' => 'RUNNING'], 'getInstanceMetrics' => ['cpu_percent' => 12.5, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null], 'listNsgRules' => [['id' => 'rule1', 'protocol' => '6', 'port_min' => 22, 'port_max' => 22, 'source' => '0.0.0.0/0', 'description' => '']]]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertStatus(200)
        ->assertSee('RUNNING');
});

it('shows provisioning progress when stack is applying', function (): void {
    $this->stack->update(['status' => 'applying', 'last_plan_summary' => 'Applying Terraform...']);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertStatus(200)
        ->assertSee('Provisioning in progress')
        ->assertSee('Applying Terraform...');
});

it('shows failed banner when stack failed', function (): void {
    $this->stack->update(['status' => 'failed', 'last_plan_summary' => 'Apply failed or timed out']);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertStatus(200)
        ->assertSee('Operation failed')
        ->assertSee('Apply failed or timed out');
});

it('shows destroying banner when stack is destroying', function (): void {
    $this->stack->update(['status' => 'destroying', 'last_plan_summary' => 'Running Terraform destroy...']);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertStatus(200)
        ->assertSee('Destroying resources');
});

it('denies power actions to team members without admin role', function (): void {
    $this->withoutVite();

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $mock = mockClient();
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->call('startInstance')
        ->assertForbidden();
});

it('adds NSG rule via OCI client', function (): void {
    $mock = mockClient();
    $mock->shouldReceive('addNsgRules')
        ->once()
        ->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..example', Mockery::on(fn ($rules): bool => $rules[0]['protocol'] === 'TCP' && $rules[0]['port_min'] === '443'), 'us-ashburn-1')
        ->andReturn(['success' => true]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('newRule.protocol', 'TCP')
        ->set('newRule.port_min', '443')
        ->set('newRule.port_max', '443')
        ->set('newRule.source', '0.0.0.0/0')
        ->call('addNsgRule')
        ->assertHasNoErrors();
});

it('removes NSG rule via OCI client', function (): void {
    $mock = mockClient();
    $mock->shouldReceive('removeNsgRules')
        ->once()
        ->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..example', ['rule-abc'], 'us-ashburn-1')
        ->andReturn(['success' => true]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->call('removeNsgRule', 'rule-abc')
        ->assertHasNoErrors();
});

it('triggers stop confirmation then calls OCI on confirm', function (): void {
    $mock = mockClient();
    $mock->shouldReceive('instanceAction')
        ->once()
        ->with(Mockery::any(), 'ocid1.instance.oc1..example', 'us-ashburn-1', 'SOFTSTOP')
        ->andReturn(['lifecycle_state' => 'STOPPING']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->call('confirmStop')
        ->assertSet('confirmingStop', true)
        ->call('stopInstance')
        ->assertSet('confirmingStop', false);
});

it('cancel clears confirmation flags', function (): void {
    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('confirmingStop', true)
        ->call('cancelConfirm')
        ->assertSet('confirmingStop', false);
});

// ── P4 tests ──────────────────────────────────────────────────────────────

it('loads and displays block volume attachments', function (): void {
    $volumeData = [
        ['id' => 'ocid1.volumeattachment.oc1..att1', 'volume_id' => 'ocid1.volume.oc1..vol1', 'display_name' => 'vol1', 'lifecycle_state' => 'ATTACHED', 'attachment_type' => 'paravirtualized', 'is_read_only' => false],
    ];
    $mock = mockClient(['listVolumeAttachments' => $volumeData]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('volumeAttachments', $volumeData)
        ->assertSee('Block volumes');
});

it('attaches a block volume via OCI client', function (): void {
    $mock = mockClient();
    $mock->shouldReceive('attachVolume')
        ->once()
        ->with(Mockery::any(), 'ocid1.instance.oc1..example', 'ocid1.volume.oc1..newvol', 'us-ashburn-1')
        ->andReturn(['id' => 'ocid1.volumeattachment.oc1..new', 'volume_id' => 'ocid1.volume.oc1..newvol', 'lifecycle_state' => 'ATTACHING', 'attachment_type' => 'paravirtualized']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('attachVolumeOcid', 'ocid1.volume.oc1..newvol')
        ->call('attachVolume')
        ->assertHasNoErrors()
        ->assertSet('attachVolumeOcid', '');
});

it('rejects attach with invalid volume OCID format', function (): void {
    $mock = mockClient();
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('attachVolumeOcid', 'not-an-ocid')
        ->call('attachVolume')
        ->assertHasErrors(['attachVolumeOcid']);
});

it('confirms then detaches a block volume', function (): void {
    $mock = mockClient(['listVolumeAttachments' => [['id' => 'att1', 'volume_id' => 'vol1', 'display_name' => null, 'lifecycle_state' => 'ATTACHED', 'attachment_type' => 'paravirtualized', 'is_read_only' => false]]]);
    $mock->shouldReceive('detachVolume')
        ->once()
        ->with(Mockery::any(), 'att1', 'us-ashburn-1')
        ->andReturn(['detached' => true]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->call('confirmDetachVolume', 'att1')
        ->assertSet('confirmingDetach', true)
        ->assertSet('pendingDetachId', 'att1')
        ->call('detachVolume')
        ->assertSet('confirmingDetach', false)
        ->assertSet('pendingDetachId', null);
});

it('loads and displays load balancers', function (): void {
    $lbData = [
        ['id' => 'ocid1.loadbalancer.oc1..lb1', 'display_name' => 'prod-lb', 'lifecycle_state' => 'ACTIVE', 'shape_name' => 'flexible', 'ip_addresses' => ['10.0.0.1'], 'backend_sets' => ['bs1']],
    ];
    $mock = mockClient(['listLoadBalancers' => $lbData]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('loadBalancers', $lbData)
        ->assertSee('prod-lb');
});

it('loads and displays autonomous databases with connection strings', function (): void {
    $adbData = [
        ['id' => 'ocid1.autonomousdatabase.oc1..adb1', 'display_name' => 'prod-adb', 'db_name' => 'PRODADB', 'lifecycle_state' => 'AVAILABLE', 'db_workload' => 'OLTP', 'cpu_core_count' => 2, 'data_storage_size_in_tbs' => 1, 'connection_strings' => ['high' => 'jdbc:...high']],
    ];
    $mock = mockClient(['listAutonomousDatabases' => $adbData]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('autonomousDatabases', $adbData)
        ->assertSee('prod-adb')
        ->assertSee('OLTP');
});

it('denies block volume actions to non-admin members', function (): void {
    $this->withoutVite();

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $mock = mockClient();
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('attachVolumeOcid', 'ocid1.volume.oc1..vol1')
        ->call('attachVolume')
        ->assertForbidden();
});

it('creates block volumes in the instance availability domain', function (): void {
    $mock = mockClient(['getInstanceStatus' => ['lifecycle_state' => 'RUNNING', 'availability_domain' => 'Uocm:US-ASHBURN-AD-2']]);
    $mock->shouldNotReceive('listAvailabilityDomains');
    $mock->shouldReceive('createVolume')->once()
        ->with(Mockery::any(), 'ocid1.compartment.oc1..example', 'Uocm:US-ASHBURN-AD-2', 'data', 100, 'us-ashburn-1')
        ->andReturn(['id' => 'ocid1.volume.oc1..v', 'display_name' => 'data']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('newVolumeName', 'data')
        ->set('newVolumeSize', 100)
        ->call('createVolume')
        ->assertHasNoErrors();
});

it('lists buckets of the server compartment', function (): void {
    $mock = mockClient(['listBuckets' => false]);
    $mock->shouldReceive('listBuckets')->atLeast()->once()
        ->with(Mockery::any(), 'ocid1.compartment.oc1..example', 'us-ashburn-1')
        ->andReturn([$bucket = ['name' => 'assets', 'namespace' => 'ns', 'storage_tier' => 'Standard', 'approximate_count' => 1, 'approximate_size' => 10, 'time_created' => null]]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('buckets', [$bucket])
        ->assertSee('assets');
});

it('loads each OCI resource once per page load', function () {
    $mock = mockClient(['listBuckets' => false, 'listLoadBalancers' => false]);
    $mock->shouldReceive('listBuckets')->once()->andReturn([]);
    $mock->shouldReceive('listLoadBalancers')->once()->andReturn([]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])->assertStatus(200);
});

it('manages the security group found on the VM network interface, even without a stack', function (): void {
    $this->stack->delete();
    $mock = mockClient(['getInstanceFirewall' => ociFirewall(['ocid1.networksecuritygroup.oc1..imported'])]);
    $mock->shouldReceive('addNsgRules')->once()
        ->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..imported', Mockery::on(fn ($rules) => $rules[0]['port_min'] === '8080'), 'us-ashburn-1')
        ->andReturn([['id' => 'r1']]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('web-nsg')
        ->assertSee('Default Security List')
        ->set('newRule.port_min', '8080')
        ->call('addNsgRule')
        ->assertHasNoErrors();
});

it('never edits a security group that is not attached to this VM', function (): void {
    $mock = mockClient();
    $mock->shouldReceive('addNsgRules')->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..someone-else', Mockery::any(), Mockery::any())->never();
    $mock->shouldReceive('addNsgRules')->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..example', Mockery::any(), Mockery::any())->andReturn([['id' => 'r']]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedNsgId', 'ocid1.networksecuritygroup.oc1..someone-else')
        ->assertSet('selectedNsgId', 'ocid1.networksecuritygroup.oc1..example')
        ->set('newRule.port_min', '443')
        ->call('addNsgRule');
});

it('validates rule ports', function (array $rule, string $field): void {
    app()->instance(OciClient::class, mockClient());

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('newRule', [...['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''], ...$rule])
        ->call('addNsgRule')
        ->assertHasErrors([$field]);
})->with([
    'tcp without port' => [['port_min' => ''], 'newRule.port_min'],
    'reversed range' => [['port_min' => '9000', 'port_max' => '8000'], 'newRule.port_max'],
]);

it('creates and attaches a security group for a VM that has none', function (): void {
    $mock = mockClient(['getInstanceFirewall' => ociFirewall([])]);
    $mock->shouldReceive('createAttachedNsg')->once()
        ->with(Mockery::any(), 'ocid1.compartment.oc1..example', 'ocid1.vcn.oc1..n', 'ocid1.vnic.oc1..v', [], Mockery::type('string'), 'us-ashburn-1')
        ->andReturn(['id' => 'ocid1.networksecuritygroup.oc1..new']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Create security group for this VM')
        ->call('createNsg')
        ->assertDispatched('success');
});

it('forbids members from changing firewall rules', function (): void {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    $mock = mockClient();
    $mock->shouldNotReceive('addNsgRules');
    $mock->shouldNotReceive('createAttachedNsg');
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('newRule.port_min', '443')
        ->call('addNsgRule')
        ->assertForbidden();
});

it('sends firewall changes of Terraform-managed VMs through plan and apply', function (): void {
    Queue::fake();
    $this->stack->update(['status' => 'provisioned', 'stack_ocid' => 'ocid1.ormstack.d', 'firewall_rules' => [['protocol' => 'TCP', 'port_min' => 22, 'source' => '0.0.0.0/0']]]);
    $mock = mockClient();
    $mock->shouldNotReceive('addNsgRules');
    $mock->shouldNotReceive('removeNsgRules');
    app()->instance(OciClient::class, $mock);

    $component = Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Managed by Terraform')
        ->set('newRule.port_min', '3000')
        ->call('addNsgRule')
        ->assertDispatched('success');

    expect(collect($this->stack->fresh()->firewall_rules)->pluck('port_min')->all())->toBe([22, 3000]);
    Queue::assertPushed(OciStackFirewallJob::class, 1);

    $component->call('removeNsgRule', 'TCP-22-22-0.0.0.0/0');
    expect(collect($this->stack->fresh()->firewall_rules)->pluck('port_min')->all())->toBe([3000]);
});

it('blocks a new firewall change while Terraform is still applying', function (): void {
    Queue::fake();
    $this->stack->update(['status' => 'provisioned', 'stack_ocid' => 'ocid1.ormstack.d', 'firewall_rules' => []]);
    OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'status' => 'in_progress', 'finished_at' => null, 'started_at' => now()]);
    app()->instance(OciClient::class, mockClient());

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->set('newRule.port_min', '3000')
        ->call('addNsgRule')
        ->assertDispatched('error');

    expect($this->stack->fresh()->firewall_rules)->toBe([]);
    Queue::assertNothingPushed();
});

it('restores a previous firewall version through Terraform', function (): void {
    Queue::fake();
    $this->stack->update(['status' => 'provisioned', 'stack_ocid' => 'ocid1.ormstack.d', 'firewall_rules' => [['protocol' => 'TCP', 'port_min' => 8080, 'source' => '0.0.0.0/0']]]);
    $old = OciStackJob::factory()->create(['oci_stack_id' => $this->stack->id, 'operation' => 'apply', 'status' => 'succeeded', 'finished_at' => now()->subDay(),
        'firewall_rules' => [['protocol' => 'TCP', 'port_min' => 80, 'port_max' => 80, 'source' => '0.0.0.0/0', 'description' => 'HTTP']]]);
    app()->instance(OciClient::class, mockClient());

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Firewall versions')
        ->assertSee('Restore')
        ->call('restoreFirewallVersion', $old->id)
        ->assertDispatched('success');

    expect(collect($this->stack->fresh()->firewall_rules)->pluck('port_min')->all())->toBe([80]);
    Queue::assertPushed(OciStackFirewallJob::class);
});

it('refuses to restore a version that belongs to another stack', function (): void {
    Queue::fake();
    $this->stack->update(['status' => 'provisioned', 'stack_ocid' => 'ocid1.ormstack.d']);
    $foreign = OciStackJob::factory()->create(['operation' => 'apply', 'status' => 'succeeded', 'firewall_rules' => [['protocol' => 'ALL', 'source' => '0.0.0.0/0']]]);
    app()->instance(OciClient::class, mockClient());

    Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->call('restoreFirewallVersion', $foreign->id)
        ->assertDispatched('error');

    Queue::assertNothingPushed();
});

it('attaches and detaches a shared firewall policy on a Terraform-managed VM', function (): void {
    Queue::fake();
    $this->stack->update(['status' => 'provisioned', 'stack_ocid' => 'ocid1.ormstack.d']);
    $policy = OciFirewallPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Public web']);
    $foreignPolicy = OciFirewallPolicy::factory()->create(['name' => 'Other team']);
    app()->instance(OciClient::class, mockClient());

    $component = Livewire::test(OciDashboard::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Shared firewall policies')
        ->assertDontSee('Other team')
        ->call('toggleFirewallPolicy', $policy->id);

    expect($this->stack->firewallPolicies()->pluck('name')->all())->toBe(['Public web']);
    Queue::assertPushed(OciStackFirewallJob::class, 1);

    $component->call('toggleFirewallPolicy', $foreignPolicy->id);
    expect($this->stack->firewallPolicies()->count())->toBe(1);
});
