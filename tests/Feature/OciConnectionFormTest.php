<?php

use App\Livewire\Security\OciConnectionForm;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders the connection form', function () {
    Livewire::test(OciConnectionForm::class)->assertStatus(200);
});

it('creates a connection when bridge validates successfully', function () {
    Http::fake([
        '*/v1/connections/validate' => Http::response(['data' => ['ok' => true, 'auth_mode' => 'api_key', 'operation' => 'get_tenancy', 'error' => null]], 200),
    ]);

    // dehydrate() zeros secrets after every round-trip request. The browser bundles
    // all form state (including PEM) with the action call in ONE Livewire commit — so
    // we must too: pass private_key as an update alongside the call, not via set().
    Livewire::test(OciConnectionForm::class)
        ->set('name', 'My OCI Connection')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        )
        ->assertDispatched('connectionAdded');

    $this->assertDatabaseHas('oci_connections', [
        'name' => 'My OCI Connection',
        'team_id' => $this->team->id,
        'region' => 'us-ashburn-1',
    ]);
});

it('sends bridge-format key names in validateConnection request', function () {
    Http::fake([
        '*/v1/connections/validate' => Http::response(['data' => ['ok' => true, 'auth_mode' => 'api_key', 'operation' => 'get_tenancy', 'error' => null]], 200),
    ]);

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Test')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        );

    Http::assertSent(function ($request) {
        $body = $request->data();
        $config = $body['config'] ?? null;

        return $config !== null
            && isset($config['auth_mode'])    // bridge name, not authentication_method
            && isset($config['tenancy_id'])   // bridge name, not tenancy_ocid
            && isset($config['user_id'])      // bridge name, not user_ocid
            && isset($config['key_content'])  // bridge name, not private_key
            && ! isset($config['tenancy_ocid'])
            && ! isset($config['private_key']);
    });
});

it('clears private_key from component state after any response', function () {
    // dehydrate() zeros secrets before every Livewire snapshot — including validation errors.
    Livewire::test(OciConnectionForm::class)
        ->set('private_key', '-----BEGIN RSA PRIVATE KEY-----secret-----END RSA PRIVATE KEY-----')
        ->set('passphrase', 'my-passphrase')
        ->set('name', '') // triggers validation error
        ->call('addConnection')
        ->assertHasErrors(['name'])
        ->assertSet('private_key', null)
        ->assertSet('passphrase', null);
});

it('does not create connection when bridge returns error', function () {
    Http::fake([
        '*/v1/connections/validate' => Http::response(['data' => ['error' => true, 'message' => 'Invalid credentials']], 200),
    ]);

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Bad Connection')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        )
        ->assertDispatched('error');

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Bad Connection']);
});

it('denies addConnection to non-admin team members', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->withoutVite();

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Sneaky Connection')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        );

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Sneaky Connection']);
});
