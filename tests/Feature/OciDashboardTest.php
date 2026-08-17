<?php

use App\Livewire\Server\OciDashboard;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\OciBridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
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

it('renders without error when instance is provisioned', function () {
    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('getInstanceStatus')->andReturn(['lifecycle_state' => 'RUNNING']);
    $mock->shouldReceive('getInstanceMetrics')->andReturn(['cpu_percent' => 12.5, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null]);
    $mock->shouldReceive('listNsgRules')->andReturn([['id' => 'rule1', 'protocol' => '6', 'port_min' => 22, 'port_max' => 22, 'source' => '0.0.0.0/0', 'description' => '']]);
    app()->instance(OciBridgeClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->assertStatus(200)
        ->assertSee('RUNNING');
});

it('shows provisioning progress when stack is applying', function () {
    $this->stack->update(['status' => 'applying', 'last_plan_summary' => 'Applying Terraform...']);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->assertStatus(200)
        ->assertSee('Provisioning in Progress')
        ->assertSee('Applying Terraform...');
});

it('shows failed banner when stack failed', function () {
    $this->stack->update(['status' => 'failed', 'last_plan_summary' => 'Apply failed or timed out']);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->assertStatus(200)
        ->assertSee('Operation Failed')
        ->assertSee('Apply failed or timed out');
});

it('shows destroying banner when stack is destroying', function () {
    $this->stack->update(['status' => 'destroying', 'last_plan_summary' => 'Running Terraform destroy...']);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->assertStatus(200)
        ->assertSee('Destroying Resources');
});

it('denies power actions to team members without admin role', function () {
    $this->withoutVite();

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('getInstanceStatus')->andReturn(['lifecycle_state' => 'RUNNING']);
    $mock->shouldReceive('getInstanceMetrics')->andReturn(['cpu_percent' => 5.0, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null]);
    $mock->shouldReceive('listNsgRules')->andReturn([]);
    app()->instance(OciBridgeClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->call('startInstance')
        ->assertForbidden();
});

it('adds NSG rule via bridge client', function () {
    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('getInstanceStatus')->andReturn(['lifecycle_state' => 'RUNNING']);
    $mock->shouldReceive('getInstanceMetrics')->andReturn(['cpu_percent' => 5.0, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null]);
    $mock->shouldReceive('listNsgRules')->andReturn([]);
    $mock->shouldReceive('addNsgRules')
        ->once()
        ->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..example', Mockery::on(fn ($rules) => $rules[0]['protocol'] === 'TCP' && $rules[0]['port_min'] === '443'))
        ->andReturn(['success' => true]);
    app()->instance(OciBridgeClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->set('newRule.protocol', 'TCP')
        ->set('newRule.port_min', '443')
        ->set('newRule.port_max', '443')
        ->set('newRule.source', '0.0.0.0/0')
        ->call('addNsgRule')
        ->assertHasNoErrors();
});

it('removes NSG rule via bridge client', function () {
    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('getInstanceStatus')->andReturn(['lifecycle_state' => 'RUNNING']);
    $mock->shouldReceive('getInstanceMetrics')->andReturn(['cpu_percent' => 5.0, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null]);
    $mock->shouldReceive('listNsgRules')->andReturn([]);
    $mock->shouldReceive('removeNsgRules')
        ->once()
        ->with(Mockery::any(), 'ocid1.networksecuritygroup.oc1..example', ['rule-abc'])
        ->andReturn(['success' => true]);
    app()->instance(OciBridgeClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->call('removeNsgRule', 'rule-abc')
        ->assertHasNoErrors();
});

it('triggers stop confirmation then calls bridge on confirm', function () {
    $mock = Mockery::mock(OciBridgeClient::class);
    $mock->shouldReceive('getInstanceStatus')->andReturn(['lifecycle_state' => 'RUNNING']);
    $mock->shouldReceive('getInstanceMetrics')->andReturn(['cpu_percent' => 5.0, 'memory_percent' => null, 'net_bytes_in' => null, 'net_bytes_out' => null]);
    $mock->shouldReceive('listNsgRules')->andReturn([]);
    $mock->shouldReceive('instanceAction')
        ->once()
        ->with(Mockery::any(), 'ocid1.instance.oc1..example', 'us-ashburn-1', 'SOFTSTOP')
        ->andReturn(['lifecycle_state' => 'STOPPING']);
    app()->instance(OciBridgeClient::class, $mock);

    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->call('confirmStop')
        ->assertSet('confirmingStop', true)
        ->call('stopInstance')
        ->assertSet('confirmingStop', false);
});

it('cancel clears confirmation flags', function () {
    Livewire::test(OciDashboard::class, ['server' => $this->server])
        ->set('confirmingStop', true)
        ->call('cancelConfirm')
        ->assertSet('confirmingStop', false);
});
