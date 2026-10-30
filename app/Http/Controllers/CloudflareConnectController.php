<?php

namespace App\Http\Controllers;

use App\Models\CloudflareConnection;
use App\Services\CloudflareAccountTokens;
use App\Services\CloudflareOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nimbus: links the session team to a Cloudflare account through the installation's OAuth client.
 */
class CloudflareConnectController extends Controller
{
    private const string SESSION_KEY = 'cloudflare_oauth';

    public function redirect(Request $request, CloudflareOAuth $oauth): RedirectResponse
    {
        $teamId = currentTeam()->id;
        abort_unless($request->user()->isAdminOfTeam($teamId), 403);

        if (! $oauth->isConfigured()) {
            return $this->back('The Cloudflare OAuth client is not configured. An instance admin can set it up in Settings → Cloudflare.');
        }

        $authorization = $oauth->authorizationRequest();
        $request->session()->put(self::SESSION_KEY, [
            'state' => $authorization['state'],
            'verifier' => $authorization['verifier'],
            'team_id' => $teamId,
            'return_to' => $this->safeReturnPath($request->query('return')),
        ]);

        return redirect()->away($authorization['url']);
    }

    public function callback(Request $request, CloudflareOAuth $oauth): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($request->query('state')) || ! hash_equals($pending['state'], $request->query('state'))) {
            return $this->back('The Cloudflare authorization expired or did not start here. Try connecting again.');
        }
        // The team was fixed when the flow started; the session team may have changed in another tab since.
        abort_unless($request->user()->isAdminOfTeam($pending['team_id']), 403);

        if (! is_string($request->query('code'))) {
            $error = (string) $request->query('error');
            $hint = str_contains($error, 'scope') ? ' The requested scopes must exist on the OAuth client: add '.CloudflareAccountTokens::SCOPE.' to it in Cloudflare, or set the client scopes in Settings → Cloudflare.' : '';

            return $this->back('Cloudflare did not authorize the connection'.($error !== '' ? ': '.$error : '.').$hint, $pending['return_to'] ?? null);
        }

        try {
            $tokens = $oauth->exchangeCode($request->query('code'), $pending['verifier']);
            $accounts = Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)
                ->get('https://api.cloudflare.com/client/v4/accounts', ['per_page' => 50])
                ->throw()->json('result') ?? [];
        } catch (\Throwable $e) {
            Log::warning('Cloudflare OAuth callback failed.', ['team_id' => $pending['team_id'], 'exception_class' => $e::class, 'message' => $e->getMessage()]);

            return $this->back('Could not connect to Cloudflare: '.$e->getMessage(), $pending['return_to'] ?? null);
        }

        $account = $accounts[0] ?? null;
        if (! $account) {
            return $this->back('The authorization did not grant access to any Cloudflare account. Check the client scopes.', $pending['return_to'] ?? null);
        }

        CloudflareConnection::query()->updateOrCreate(['team_id' => $pending['team_id']], [
            'connected_by' => $request->user()->id,
            'account_id' => $account['id'],
            'account_name' => $account['name'] ?? $account['id'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at'],
            'scopes' => $tokens['scopes'] ?? $oauth->scopes(),
        ]);

        auditLog('ui.team.cloudflare.connected', ['team_id' => $pending['team_id'], 'account_id' => $account['id']]);

        $message = 'Cloudflare account '.($account['name'] ?? $account['id']).' connected.';

        return ($pending['return_to'] ?? null)
            ? redirect($pending['return_to'])->with('success', $message)
            : redirect()->route('security.cloudflare')->with('success', $message);
    }

    /**
     * Cloudflare redirects here, instead of the callback, when the authorization request itself is invalid,
     * most often because the OAuth client was created without a scope the panel now asks for.
     */
    public function error(Request $request): RedirectResponse
    {
        $detail = collect([$request->query('error'), $request->query('error_description')])->filter(fn ($v) => is_string($v) && $v !== '')->implode(': ');

        return $this->back('Cloudflare rejected the authorization'.($detail !== '' ? " ({$detail})" : '').'. The OAuth client must offer every requested scope: add '.CloudflareAccountTokens::SCOPE.' to it in the Cloudflare dashboard, or set the client scopes in Settings → Cloudflare.');
    }

    /**
     * Only a local path, so the callback can never become an open redirect.
     */
    private function safeReturnPath(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^/(?!/)[A-Za-z0-9/_-]*$#', $path) ? $path : null;
    }

    private function back(string $error, ?string $returnTo = null): RedirectResponse
    {
        return ($returnTo ? redirect($returnTo) : redirect()->route('security.cloudflare'))->with('error', $error);
    }
}
