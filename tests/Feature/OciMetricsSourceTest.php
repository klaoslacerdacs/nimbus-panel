<?php

use App\Actions\Server\StopSentinel;
use App\Livewire\Server\Charts;
use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'oci_connection_id' => OciConnection::factory()->create(['team_id' => $this->team->id])->id,
        'oci_instance_id' => 'ocid1.instance.oc1..vm',
        'oci_compartment_id' => 'ocid1.compartment.oc1..c',
        'oci_region' => 'sa-saopaulo-1',
        'oci_metrics_source' => 'oci_monitoring',
    ]);
});

it('disables Sentinel (and its out-of-sync alerts) when OCI Monitoring is the metrics source', function (): void {
    expect($this->server->isSentinelEnabled())->toBeFalse()
        ->and($this->server->usesOciMonitoring())->toBeTrue();

    $this->server->update(['oci_metrics_source' => 'sentinel']);
    expect($this->server->fresh()->isSentinelEnabled())->toBeTrue();
});

it('keeps Sentinel for servers that are not on OCI', function (): void {
    $plain = Server::factory()->create(['team_id' => $this->team->id]);

    expect($plain->usesOciMonitoring())->toBeFalse()
        ->and($plain->isSentinelEnabled())->toBeTrue();
});

it('serves CPU and memory history from OCI Monitoring in the Sentinel series format', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('getInstanceMetricSeries')->once()
        ->with(Mockery::any(), 'ocid1.instance.oc1..vm', 'ocid1.compartment.oc1..c', 'sa-saopaulo-1', 30)
        ->andReturn(['cpu' => [[1760000000000, 2.5]], 'memory' => [[1760000000000, 10.0]]]);
    app()->instance(OciClient::class, $mock);

    expect($this->server->getCpuMetrics(30))->toBe([[1760000000000, 2.5]])
        ->and($this->server->getMemoryMetrics(30))->toBe([[1760000000000, 10.0]]);
});

it('stops Sentinel when switching a server to OCI Monitoring', function (): void {
    $this->server->update(['oci_metrics_source' => 'sentinel']);
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);
    StopSentinel::shouldRun()->once();

    Livewire\Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('oci_metrics_source', 'oci_monitoring')
        ->call('saveOciMetricsSource');

    expect($this->server->fresh()->oci_metrics_source)->toBe('oci_monitoring');
});

it('renders the metrics tab from OCI Monitoring without Sentinel controls', function (): void {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    Livewire\Livewire::test(Charts::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('reported by OCI Monitoring')
        ->assertDontSee('Disable metrics')
        ->assertDontSee('Push interval');
});

it('stops an orphaned Sentinel that keeps pushing from an OCI Monitoring server', function (): void {
    config(['app.maintenance.store' => 'array']);
    Queue::fake();
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $this->postJson('/api/v1/sentinel/push', ['containers' => []], ['Authorization' => 'Bearer '.$this->server->settings->sentinel_token])
        ->assertNoContent();

    StopSentinel::assertPushed(fn ($action, $args) => $args[0]->is($this->server));
});
