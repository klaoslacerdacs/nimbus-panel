<?php

namespace App\Services;

use App\Models\CloudflareConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Authorization Code + PKCE against Cloudflare's OAuth server, using the installation's client
 * (Settings → Cloudflare) with client_secret_basic.
 */
class CloudflareOAuth
{
    public const string AUTHORIZE_URL = 'https://dash.cloudflare.com/oauth2/auth';

    public const string TOKEN_URL = 'https://dash.cloudflare.com/oauth2/token';

    /** What tunnels + DNS need; Settings → Cloudflare overrides it only when a client was created with other scopes. */
    public const string DEFAULT_SCOPES = 'account-settings.read zone.read dns.read dns.write teams.read teams.write';

    public const string REVOKE_URL = 'https://dash.cloudflare.com/oauth2/revoke';

    public function isConfigured(): bool
    {
        $settings = instanceSettings();

        return filled($settings->cloudflare_oauth_client_id) && filled($settings->cloudflare_oauth_client_secret);
    }

    public function redirectUri(): string
    {
        return url('/auth/cloudflare/callback');
    }

    /**
     * Only scopes the client allows: Cloudflare rejects the whole request (/oauth/error) for any other, including
     * offline_access. Refresh tokens come from the client's refresh_token grant.
     */
    public function scopes(): string
    {
        return implode(' ', preg_split('/[\s,]+/', instanceSettings()->cloudflare_oauth_scopes ?: self::DEFAULT_SCOPES, -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * @return array{url: string, state: string, verifier: string}
     */
    public function authorizationRequest(): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => instanceSettings()->cloudflare_oauth_client_id,
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scopes(),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: Carbon|null, scopes: string|null}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verifier,
        ]);
    }

    /**
     * Cloudflare rotates refresh tokens and revokes the grant when an old one is reused, so concurrent
     * refreshes must not both send the same token.
     */
    public function refresh(CloudflareConnection $connection): void
    {
        Cache::lock("cloudflare-refresh:{$connection->id}", 30)->block(20, function () use ($connection): void {
            $connection->refresh();
            if ($connection->expires_at?->isAfter(now()->addMinute())) {
                return;
            }

            $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
            $connection->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => $tokens['expires_at'],
            ]);
        });
    }

    /**
     * Best effort: the connection is removed locally even when Cloudflare cannot be reached.
     */
    public function revoke(CloudflareConnection $connection): void
    {
        $settings = instanceSettings();
        rescue(fn () => Http::asForm()->timeout(10)
            ->withBasicAuth((string) $settings->cloudflare_oauth_client_id, (string) $settings->cloudflare_oauth_client_secret)
            ->post(self::REVOKE_URL, ['token' => $connection->refresh_token ?? $connection->access_token]), report: false);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string|null, expires_at: Carbon|null, scopes: string|null}
     */
    private function tokenRequest(array $params): array
    {
        $settings = instanceSettings();
        $response = Http::asForm()->acceptJson()->timeout(20)
            ->withBasicAuth((string) $settings->cloudflare_oauth_client_id, (string) $settings->cloudflare_oauth_client_secret)
            ->post(self::TOKEN_URL, $params);

        $body = $response->json() ?? [];
        if (! $response->successful() || empty($body['access_token'])) {
            throw new RuntimeException('Cloudflare did not issue a token: '.($body['error_description'] ?? $body['error'] ?? 'HTTP '.$response->status()));
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_at' => isset($body['expires_in']) ? now()->addSeconds((int) $body['expires_in']) : null,
            'scopes' => $body['scope'] ?? null,
        ];
    }
}
