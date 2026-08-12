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

it('does not allow team ownership to be mass assigned', function () {
    $stack = new OciStack([
        'name' => 'Production Stack',
        'team_id' => 123,
        'oci_connection_id' => 456,
    ]);

    expect($stack->team_id)->toBeNull()
        ->and($stack->oci_connection_id)->toBeNull();
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
