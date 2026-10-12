<?php

use App\Livewire\Security\Cloudflare;
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
    Http::preventStrayRequests();
    InstanceSettings::forceCreate(['id' => 0, 'cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret', 'cloudflare_oauth_scopes' => 'tunnel:edit dns:edit']);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

function startCloudflareFlow(): array
{
    $location = test()->get(route('cloudflare.connect'))->assertRedirect()->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

it('redirects to Cloudflare with PKCE and offline_access', function (): void {
    $query = startCloudflareFlow();

    expect($query)->toMatchArray(['response_type' => 'code', 'client_id' => 'cf-client', 'code_challenge_method' => 'S256', 'scope' => 'tunnel:edit dns:edit offline_access'])
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and($query['redirect_uri'])->toBe(url('/auth/cloudflare/callback'));
});

it('stores an encrypted connection for the team that started the flow', function (): void {
    Http::fake([
        'dash.cloudflare.com/oauth2/token' => Http::response(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600, 'scope' => 'tunnel:edit offline_access']),
        'api.cloudflare.com/client/v4/accounts*' => Http::response(['result' => [['id' => 'acc-1', 'name' => 'Nimbus']]]),
    ]);
    $query = startCloudflareFlow();

    $this->get(route('cloudflare.callback', ['code' => 'the-code', 'state' => $query['state']]))->assertRedirect(route('security.cloudflare'));

    $connection = CloudflareConnection::query()->sole();
    expect($connection->team_id)->toBe($this->team->id)
        ->and($connection->account_name)->toBe('Nimbus')
        ->and($connection->access_token)->toBe('at-1')
        ->and(DB::table('cloudflare_connections')->value('access_token'))->not->toContain('at-1')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);

    Http::assertSent(fn ($request) => $request->url() === 'https://dash.cloudflare.com/oauth2/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('cf-client:cf-secret'))
        && $request['grant_type'] === 'authorization_code'
        && filled($request['code_verifier']));
});

it('rejects a callback whose state does not match', function (): void {
    startCloudflareFlow();

    $this->get(route('cloudflare.callback', ['code' => 'x', 'state' => 'forged']))->assertRedirect(route('security.cloudflare'));

    expect(CloudflareConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the team fixed at the start even if the session team changes', function (): void {
    Http::fake([
        'dash.cloudflare.com/oauth2/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
        'api.cloudflare.com/client/v4/accounts*' => Http::response(['result' => [['id' => 'acc-1', 'name' => 'Nimbus']]]),
    ]);
    $query = startCloudflareFlow();
    $otherTeam = Team::factory()->create();
    $this->admin->teams()->attach($otherTeam, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $this->get(route('cloudflare.callback', ['code' => 'c', 'state' => $query['state']]));

    expect(CloudflareConnection::query()->sole()->team_id)->toBe($this->team->id);
});

it('does not let members connect or disconnect', function (): void {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($member);

    $this->get(route('cloudflare.connect'))->assertForbidden();
    Livewire::test(Cloudflare::class)->call('disconnect')->assertForbidden();
    expect(CloudflareConnection::query()->count())->toBe(1);
});

it('revokes and deletes the connection on disconnect', function (): void {
    Http::fake(['dash.cloudflare.com/oauth2/revoke' => Http::response()]);
    CloudflareConnection::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(Cloudflare::class)->assertSee('Nimbus Network')->call('disconnect');

    expect(CloudflareConnection::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://dash.cloudflare.com/oauth2/revoke' && $request['token'] === 'cf-refresh-token');
});

it('refreshes an expiring token before calling the API', function (): void {
    Http::fake(['dash.cloudflare.com/oauth2/token' => Http::response(['access_token' => 'at-new', 'expires_in' => 3600])]);
    $connection = CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'expires_at' => now()->subMinute()]);

    $connection->api();

    expect($connection->fresh()->access_token)->toBe('at-new')
        ->and($connection->fresh()->refresh_token)->toBe('cf-refresh-token');
});
