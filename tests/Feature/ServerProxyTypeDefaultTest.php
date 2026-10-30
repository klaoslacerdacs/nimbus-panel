<?php

use App\Enums\ProxyTypes;
use App\Enums\ServerRole;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('servers without a proxy type get Traefik, while an explicit choice and build servers are left alone', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $untyped = Server::factory()->create(['team_id' => $team->id]);
    $none = Server::factory()->create(['team_id' => $team->id]);
    $none->proxy->set('type', ProxyTypes::NONE->value);
    $none->save();
    $build = Server::factory()->create(['team_id' => $team->id]);
    $build->settings->update(['server_role' => ServerRole::BUILD, 'is_build_server' => true]);

    expect($untyped->proxyType())->toBeNull()
        ->and(Server::assignDefaultProxyType())->toBe(1)
        ->and($untyped->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value)
        ->and($untyped->fresh()->proxy->get('status'))->toBe('exited')
        ->and($none->fresh()->proxyType())->toBe(ProxyTypes::NONE->value)
        ->and($build->fresh()->proxyType())->toBeNull()
        ->and(Server::assignDefaultProxyType())->toBe(0);
});
