<?php

use App\Livewire\Security\OciConnectionForm;
use App\Models\InstanceSettings;
use App\Models\OciConnection;
use App\Models\Team;
use App\Models\User;
use App\Services\OciClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders the connection form', function (): void {
    Livewire::test(OciConnectionForm::class)->assertStatus(200);
});

it('creates a connection when OCI API validates successfully', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')->andReturn(['ok' => true]);
    app()->instance(OciClient::class, $mock);

    // dehydrate() zeros secrets after every round-trip. The browser bundles all form
    // state (including PEM) with the action call in ONE Livewire commit — simulate that
    // by passing private_key as an update alongside the call, not via a separate set().
    Livewire::test(OciConnectionForm::class)
        ->set('name', 'My OCI Connection')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99')
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

it('sends API-config key names in validateConnection request', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')
        ->once()
        ->with(Mockery::on(fn ($config): bool => isset($config['auth_mode'])     // API config key, not authentication_method
            && isset($config['tenancy_id'])   // API config key, not tenancy_ocid
            && isset($config['user_id'])      // API config key, not user_ocid
            && isset($config['key_content'])  // API config key, not private_key
            && ! isset($config['tenancy_ocid'])
            && ! isset($config['private_key'])))
        ->andReturn(['ok' => true]);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Test')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        );
});

it('clears private_key from component state after addConnection runs', function (): void {
    // Inject PEM alongside the call (same Livewire commit) so it IS populated when
    // addConnection executes. After the method returns, dehydrate() must zero it.
    // If dehydrate() was removed, private_key would survive in the snapshot.
    Livewire::test(OciConnectionForm::class)
        ->set('fingerprint', 'invalid')  // deliberately invalid — triggers validation error
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: [
                'private_key' => '-----BEGIN RSA PRIVATE KEY-----secret-----END RSA PRIVATE KEY-----',
                'passphrase' => 'my-passphrase',
            ],
        )
        ->assertHasErrors(['fingerprint'])
        ->assertSet('private_key', null)
        ->assertSet('passphrase', null);
});

it('does not create connection when OCI API returns error', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')->andReturn(['error' => true, 'message' => 'Invalid credentials']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Bad Connection')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        )
        ->assertDispatched('error');

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Bad Connection']);
});

it('denies addConnection to non-admin team members', function (): void {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    expect($member->cannot('create', OciConnection::class))->toBeTrue();

    $this->withoutVite();

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Sneaky Connection')
        ->set('authentication_method', 'api_key')
        ->set('region', 'us-ashburn-1')
        ->set('tenancy_ocid', 'ocid1.tenancy.oc1..aaa')
        ->set('user_ocid', 'ocid1.user.oc1..bbb')
        ->set('fingerprint', 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99')
        ->update(
            calls: [['method' => 'addConnection', 'params' => [], 'path' => '']],
            updates: ['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----"],
        )
        ->assertDispatched('error');

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Sneaky Connection']);
});

it('restricts instance_principal auth to instance administrators', function (): void {
    $mock = Mockery::mock(OciClient::class);
    $mock->shouldNotReceive('validateConnection');
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Instance Principal Conn')
        ->set('authentication_method', 'instance_principal')
        ->set('region', 'us-ashburn-1')
        ->call('addConnection')
        ->assertHasErrors(['authentication_method']);

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Instance Principal Conn']);
});

it('lets instance administrators create an instance_principal connection in the root team without key material', function (): void {
    $root = Team::factory()->create(['id' => 0]);
    $root->members()->attach($this->user, ['role' => 'admin']);
    $this->user->refresh();
    session(['currentTeam' => $root]);

    $mock = Mockery::mock(OciClient::class);
    $mock->shouldReceive('validateConnection')
        ->once()
        ->with(Mockery::on(fn ($config): bool => $config['auth_mode'] === 'instance_principal' && ! isset($config['key_content'])))
        ->andReturn(['ok' => true, 'tenancy_ocid' => 'ocid1.tenancy.oc1..host']);
    app()->instance(OciClient::class, $mock);

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Host identity')
        ->set('authentication_method', 'instance_principal')
        ->set('region', 'sa-saopaulo-1')
        ->set('private_key', 'should-be-ignored')
        ->call('addConnection')
        ->assertHasNoErrors()
        ->assertDispatched('connectionAdded');

    $connection = OciConnection::where('name', 'Host identity')->firstOrFail();
    expect($connection->tenancy_ocid)->toBe('ocid1.tenancy.oc1..host')
        ->and($connection->private_key)->toBeNull()
        ->and($connection->toApiConfig())->toBe(['auth_mode' => 'instance_principal', 'region' => 'sa-saopaulo-1']);
});

it('never lets an instance_principal connection belong to a customer team', function (): void {
    $root = Team::factory()->create(['id' => 0]);
    $root->members()->attach($this->user, ['role' => 'admin']);
    $this->user->refresh();

    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Leaked host identity')
        ->set('authentication_method', 'instance_principal')
        ->set('region', 'us-ashburn-1')
        ->call('addConnection')
        ->assertHasErrors(['authentication_method']);

    $this->assertDatabaseMissing('oci_connections', ['name' => 'Leaked host identity']);
});

it('rejects regions outside the OCI region list', function (): void {
    Livewire::test(OciConnectionForm::class)
        ->set('name', 'Bad region')
        ->set('region', 'evil.com/x?')
        ->call('addConnection')
        ->assertHasErrors(['region']);
});
