<?php

use App\Livewire\Security\IntegrationTokenForm;
use App\Models\CloudflareConnection;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use App\Services\CloudflareTokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    InstanceSettings::forceCreate(['id' => 0, 'cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret']);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

function fakeCloudflareTokenChecks(): array
{
    return [
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com', 'account' => ['id' => 'acc123', 'name' => 'Nimbus Network']]], 'result_info' => ['total_pages' => 1]]),
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['result' => []]),
        'api.cloudflare.com/client/v4/accounts/acc123/cfd_tunnel*' => Http::response(['result' => []]),
    ];
}

it('creates the Cloudflare token from the connected account and saves it', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123', 'account_name' => 'Nimbus Network']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/permission_groups' => Http::response(['result' => [
            ['id' => 'pg-dns', 'name' => 'DNS Write', 'scopes' => ['com.cloudflare.api.account.zone']],
            ['id' => 'pg-zone', 'name' => 'Zone Read', 'scopes' => ['com.cloudflare.api.account.zone']],
            ['id' => 'pg-tunnel', 'name' => 'Cloudflare Tunnel Write', 'scopes' => ['com.cloudflare.api.account']],
        ]]),
        'api.cloudflare.com/client/v4/accounts/acc123/tokens' => Http::response(['result' => ['id' => 'tok-1', 'status' => 'active', 'value' => 'cfat_created-secret']]),
        ...fakeCloudflareTokenChecks(),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->assertSee('Create from Nimbus Network')
        ->set('name', 'Production')
        ->set('capabilities', ['dns', 'tunnel'])
        ->call('createFromCloudflare')
        ->assertHasNoErrors()
        ->assertDispatched('integrationTokenAdded')
        ->assertSet('token', '');

    $token = IntegrationToken::query()->sole();
    expect($token->token)->toBe('cfat_created-secret')
        ->and($token->provider)->toBe('cloudflare')
        ->and($token->name)->toBe('Production')
        ->and($token->capabilities)->toBe(['dns', 'tunnel'])
        ->and($token->team_id)->toBe($this->team->id);

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'https://api.cloudflare.com/client/v4/accounts/acc123/tokens' || $request->method() !== 'POST') {
            return false;
        }
        $policies = collect($request['policies']);
        $ids = $policies->flatMap(fn ($policy) => collect($policy['permission_groups'])->pluck('id'))->sort()->values()->all();

        return $request['name'] === 'Nimbus Panel: Production'
            && $ids === ['pg-dns', 'pg-tunnel', 'pg-zone']
            && $policies->contains(fn ($policy) => isset($policy['resources']['com.cloudflare.api.account.acc123']))
            && $request->hasHeader('Authorization', 'Bearer cf-access-token');
    });
    // The account token is verified through its own capabilities, never through the user token endpoint.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/user/tokens/verify'));
});

it('saves the token created from Cloudflare when the form is submitted with an empty token', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/permission_groups' => Http::response(['result' => [
            ['id' => 'pg-dns', 'name' => 'DNS Edit'], ['id' => 'pg-zone', 'name' => 'Zone Read'],
        ]]),
        'api.cloudflare.com/client/v4/accounts/acc123/tokens' => Http::response(['result' => ['id' => 'tok-2', 'value' => 'cfat_from-submit']]),
        ...fakeCloudflareTokenChecks(),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'DNS only')
        ->call('addToken')
        ->assertHasNoErrors()
        ->assertDispatched('integrationTokenAdded');

    expect(IntegrationToken::query()->sole()->token)->toBe('cfat_from-submit');
});

it('deletes the created Cloudflare token and clears the form when it cannot be saved', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/permission_groups' => Http::response(['result' => [
            ['id' => 'pg-dns', 'name' => 'DNS Write'], ['id' => 'pg-zone', 'name' => 'Zone Read'],
        ]]),
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/tok-x' => Http::response(['result' => ['id' => 'tok-x']]),
        'api.cloudflare.com/client/v4/accounts/acc123/tokens' => Http::response(['result' => ['id' => 'tok-x', 'value' => 'cfat_x']]),
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['errors' => [['message' => 'forbidden']]], 403),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'Production')
        ->call('createFromCloudflare')
        ->assertDispatched('error')
        ->assertSet('token', '');

    expect(IntegrationToken::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acc123/tokens/tok-x');
});

it('does not let a member create a token from Cloudflare', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake();
    $component = Livewire::test(IntegrationTokenForm::class)->set('name', 'Production');

    $this->team->members()->updateExistingPivot($this->admin->id, ['role' => 'member']);
    $this->admin->load('teams');

    $component->call('createFromCloudflare')->assertForbidden();
    Http::assertNothingSent();
    expect(IntegrationToken::query()->count())->toBe(0);
});

it('does not offer the Cloudflare account of another team', function (): void {
    CloudflareConnection::factory()->create(['team_id' => Team::factory()->create()->id, 'account_name' => 'Other Corp']);

    Livewire::test(IntegrationTokenForm::class)
        ->assertDontSee('Create from')
        ->assertSee('Login with Cloudflare');
});

it('asks to reconnect when the Cloudflare login expired and cannot be refreshed', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_name' => 'Nimbus Network', 'expires_at' => now()->subDay(), 'refresh_token' => null]);

    Livewire::test(IntegrationTokenForm::class)
        ->assertSee('Reconnect Cloudflare')
        ->assertDontSee('Create from');
});

it('explains the missing OAuth scope when Cloudflare refuses to create the token', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/permission_groups' => Http::response(['errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'Production')
        ->call('createFromCloudflare')
        ->assertDispatched('error', fn (string $name, array $params) => str_contains(implode(' ', $params), 'account-api-tokens.write'));

    expect(IntegrationToken::query()->count())->toBe(0);
});

it('offers the Cloudflare login when the team is not connected yet', function (): void {
    Livewire::test(IntegrationTokenForm::class)
        ->assertSee('Login with Cloudflare')
        ->assertDontSee('Create from');
});

it('hides the Cloudflare login when the OAuth client is not configured', function (): void {
    InstanceSettings::query()->update(['cloudflare_oauth_client_id' => null, 'cloudflare_oauth_client_secret' => null]);

    Livewire::test(IntegrationTokenForm::class)
        ->assertDontSee('Login with Cloudflare')
        ->assertDontSee('Create from');
});

it('verifies account tokens by their capabilities instead of the user token endpoint', function (): void {
    Http::fake(fakeCloudflareTokenChecks());

    expect(app(CloudflareTokenValidator::class)->validate('cfat_account-token', ['dns', 'tunnel']))->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/user/tokens/verify'));
});
