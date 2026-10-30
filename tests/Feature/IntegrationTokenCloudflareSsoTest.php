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
        ->assertDispatched('integrationTokenAdded');

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

it('does not create a Cloudflare token without the DNS checks passing', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/permission_groups' => Http::response(['result' => [
            ['id' => 'pg-dns', 'name' => 'DNS Write'], ['id' => 'pg-zone', 'name' => 'Zone Read'],
        ]]),
        'api.cloudflare.com/client/v4/accounts/acc123/tokens' => Http::response(['result' => ['value' => 'cfat_x']]),
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['errors' => [['message' => 'forbidden']]], 403),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'Production')
        ->call('createFromCloudflare')
        ->assertDispatched('error');

    expect(IntegrationToken::query()->count())->toBe(0);
});

it('explains the missing OAuth scope when Cloudflare refuses to create the token', function (): void {
    CloudflareConnection::factory()->create(['team_id' => $this->team->id, 'account_id' => 'acc123']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acc123/tokens/permission_groups' => Http::response(['errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'Production')
        ->call('createFromCloudflare')
        ->assertDispatched('error', fn (string $name, array $params) => str_contains(implode(' ', $params), 'account_api_tokens:create'));

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
