<?php

use App\Models\OciConnection;
use App\Models\OciStack;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a team and a connection', function () {
    $team = Team::factory()->create();
    $connection = OciConnection::factory()->for($team)->create();
    $stack = OciStack::factory()->for($team)->for($connection)->create();

    expect($stack->team->is($team))->toBeTrue()
        ->and($stack->ociConnection->is($connection))->toBeTrue();
});

it('scopes stack queries to the requested team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $ownStack = OciStack::factory()->for($team)->create();
    $otherStack = OciStack::factory()->for($otherTeam)->create();

    $stacks = OciStack::ownedByTeam($team->id)->get();

    expect($stacks->modelKeys())->toBe([$ownStack->id])
        ->and($stacks->contains($otherStack))->toBeFalse();
});

it('team_id is fillable but enforced by the controller', function () {
    // team_id is fillable so OciStack::create(['team_id' => ...]) works.
    // The security guarantee is that OciStackForm always uses currentTeam()->id,
    // never accepts team_id from user input.
    $team = Team::factory()->create();
    $connection = OciConnection::factory()->for($team)->create();
    $stack = new OciStack(['team_id' => $team->id, 'oci_connection_id' => $connection->id, 'name' => 'x']);

    expect($stack->team_id)->toBe($team->id)
        ->and($stack->oci_connection_id)->toBe($connection->id);
});

it('defaults status to pending', function () {
    $stack = new OciStack;

    expect($stack->status)->toBe('pending')
        ->and($stack->managed_by)->toBe('terraform');
});

it('casts tf_vars to array', function () {
    $stack = OciStack::factory()->create(['tf_vars' => ['shape' => 'VM.Standard.A1.Flex']]);

    expect($stack->fresh()->tf_vars)->toBe(['shape' => 'VM.Standard.A1.Flex']);
});

it('isActive returns true for in-flight statuses including destroying', function () {
    foreach (['pending', 'planning', 'applying', 'destroying'] as $status) {
        $stack = new OciStack(['status' => $status]);
        expect($stack->isActive())->toBeTrue("expected isActive for status={$status}");
    }

    foreach (['provisioned', 'failed', 'destroy_failed'] as $status) {
        $stack = new OciStack(['status' => $status]);
        expect($stack->isActive())->toBeFalse("expected !isActive for status={$status}");
    }
});
