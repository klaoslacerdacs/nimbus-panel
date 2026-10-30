<?php

use App\Livewire\SettingsCloudflare;
use App\Models\CloudflareConnection;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->root = Team::factory()->create(['id' => 0]);
    $this->admin = User::factory()->create();
    $this->root->members()->attach($this->admin, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->root]);
});

it('shows the callback URL and the values to use in Cloudflare', function (): void {
    Livewire::test(SettingsCloudflare::class)
        ->assertSee(url('/auth/cloudflare/callback'))
        ->assertSee('client_secret_basic')
        ->assertSee('Not configured');
});

it('stores the OAuth client with the secret encrypted and never sends it back', function (): void {
    Livewire::test(SettingsCloudflare::class)
        ->set('clientId', 'cf-client-123')
        ->set('scopes', 'tunnel:edit dns:edit')
        ->update(calls: [['method' => 'save', 'params' => [], 'path' => '']], updates: ['clientSecret' => 'super-secret-value'])
        ->assertHasNoErrors()
        ->assertSet('clientSecret', null)
        ->assertSee('Configured')
        ->assertDontSee('super-secret-value');

    $settings = InstanceSettings::get();
    expect($settings->cloudflare_oauth_client_id)->toBe('cf-client-123')
        ->and($settings->cloudflare_oauth_client_secret)->toBe('super-secret-value')
        ->and($settings->cloudflare_oauth_scopes)->toBe('tunnel:edit dns:edit')
        ->and(DB::table('instance_settings')->value('cloudflare_oauth_client_secret'))->not->toContain('super-secret-value');
});

it('keeps the stored secret when the secret field is left blank', function (): void {
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'old-id', 'cloudflare_oauth_client_secret' => 'kept-secret']);

    Livewire::test(SettingsCloudflare::class)
        ->set('clientId', 'new-id')
        ->set('scopes', 'tunnel:edit')
        ->call('save')
        ->assertHasNoErrors();

    expect(InstanceSettings::get()->cloudflare_oauth_client_id)->toBe('new-id')
        ->and(InstanceSettings::get()->cloudflare_oauth_client_secret)->toBe('kept-secret');
});

it('requires a secret the first time', function (): void {
    Livewire::test(SettingsCloudflare::class)
        ->set('clientId', 'cf-client-123')
        ->call('save')
        ->assertHasErrors(['clientSecret']);
});

it('removes the OAuth client', function (): void {
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'id', 'cloudflare_oauth_client_secret' => 'secret']);

    Livewire::test(SettingsCloudflare::class)->call('remove');

    expect(InstanceSettings::get()->cloudflare_oauth_client_id)->toBeNull()
        ->and(InstanceSettings::get()->cloudflare_oauth_client_secret)->toBeNull();
});

it('is only for instance administrators', function (): void {
    $other = Team::factory()->create();
    $owner = User::factory()->create();
    $other->members()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $other]);

    Livewire::test(SettingsCloudflare::class)->assertRedirect(route('dashboard'));

    // A page opened by an admin cannot be used to save once the session is no longer an instance admin.
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->root]);
    $component = Livewire::test(SettingsCloudflare::class)->set('clientId', 'hijack');
    $this->actingAs($owner);
    session(['currentTeam' => $other]);
    $component->call('save')->assertForbidden();
    expect(InstanceSettings::get()->cloudflare_oauth_client_id)->toBeNull();
});

it('refuses the client secret pasted as the client ID', function (): void {
    Livewire::test(SettingsCloudflare::class)
        ->set('clientId', 'cfoc_j5zCUSLFatADaqAyN3B55KbWN9oRKpqW')
        ->update(calls: [['method' => 'save', 'params' => [], 'path' => '']], updates: ['clientSecret' => 'ad1b5abc'])
        ->assertHasErrors(['clientId' => 'not_regex']);
});

it('says the Cloudflare login is OK when the connected account still answers', function (): void {
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret']);
    CloudflareConnection::factory()->create(['team_id' => 0]);
    Http::fake(['api.cloudflare.com/client/v4/accounts/acc123' => Http::response(['result' => ['id' => 'acc123']])]);

    Livewire::test(SettingsCloudflare::class)
        ->assertSee('Reconnect')
        ->assertSee('connected to')
        ->call('reconnect')
        ->assertNoRedirect()
        ->assertDispatched('success', 'Cloudflare login OK: Nimbus Network.');
});

it('sends to the Cloudflare login when the connection expired, was revoked or does not exist', function (): void {
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret']);
    $login = route('cloudflare.connect', ['return' => '/settings/cloudflare']);

    Livewire::test(SettingsCloudflare::class)->assertSee('not connected')->call('reconnect')->assertRedirect($login);

    $connection = CloudflareConnection::factory()->create(['team_id' => 0, 'expires_at' => now()->subDay(), 'refresh_token' => null]);
    Livewire::test(SettingsCloudflare::class)->assertSee('login expired')->call('reconnect')->assertRedirect($login);

    $connection->update(['expires_at' => now()->addHour()]);
    Http::fake(['api.cloudflare.com/client/v4/accounts/acc123' => Http::response(['errors' => [['message' => 'revoked']]], 401)]);
    Livewire::test(SettingsCloudflare::class)->call('reconnect')->assertRedirect($login);
});

it('reports a Cloudflare outage instead of forcing a new login', function (): void {
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret']);
    CloudflareConnection::factory()->create(['team_id' => 0]);
    Http::fake(['api.cloudflare.com/client/v4/accounts/acc123' => Http::response('', 503)]);

    Livewire::test(SettingsCloudflare::class)
        ->call('reconnect')
        ->assertNoRedirect()
        ->assertDispatched('error', fn (string $name, array $params) => str_contains(implode(' ', $params), '503'));
});

it('does not let an instance admin reconnect a team they only belong to', function (): void {
    InstanceSettings::get()->update(['cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret']);
    $other = Team::factory()->create();
    $other->members()->attach($this->admin, ['role' => 'member']);
    session(['currentTeam' => $other]);

    Livewire::test(SettingsCloudflare::class)
        ->assertDontSee('Reconnect')
        ->call('reconnect')
        ->assertForbidden();
});

it('hides Reconnect until the OAuth client is saved', function (): void {
    Livewire::test(SettingsCloudflare::class)
        ->assertDontSee('Reconnect')
        ->call('reconnect')
        ->assertNoRedirect()
        ->assertDispatched('error');
});
