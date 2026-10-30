<?php

use App\Models\CloudflareConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserResourceStack([
        'instanceSettings' => ['cloudflare_oauth_client_id' => 'cf-client', 'cloudflare_oauth_client_secret' => 'cf-secret'],
    ]);
});

// The browser plugin only wires a test to Playwright when its closure calls visit() itself.
function openNewIntegrationToken(mixed $page): mixed
{
    return $page->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->wait(1.5)
        ->navigate('/security/integration-tokens')
        ->click('New token')
        ->wait(1);
}

it('offers the Cloudflare login on the new token form when the team is not connected', function () {
    $page = visit('/login');

    openNewIntegrationToken($page)
        ->assertSee('Login with Cloudflare')
        ->assertSee('Create token at Cloudflare')
        ->screenshot(filename: 'integration-token-cloudflare-login');
});

it('offers to create the token from the connected Cloudflare account', function () {
    CloudflareConnection::factory()->create(['team_id' => 0, 'account_name' => 'Nimbus Network']);
    $page = visit('/login');

    openNewIntegrationToken($page)
        ->assertSee('Create from Nimbus Network')
        ->assertSee('API token (optional)')
        ->screenshot(filename: 'integration-token-cloudflare-connected');
});
