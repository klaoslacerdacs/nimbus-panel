<?php

namespace App\Http\Controllers;

use App\Models\CloudflareConnection;
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
            return $this->back('Cloudflare did not authorize the connection'.($request->query('error') ? ': '.$request->query('error') : '.'));
        }

        try {
            $tokens = $oauth->exchangeCode($request->query('code'), $pending['verifier']);
            $accounts = Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)
                ->get('https://api.cloudflare.com/client/v4/accounts', ['per_page' => 50])
                ->throw()->json('result') ?? [];
        } catch (\Throwable $e) {
            Log::warning('Cloudflare OAuth callback failed.', ['team_id' => $pending['team_id'], 'exception_class' => $e::class, 'message' => $e->getMessage()]);

            return $this->back('Could not connect to Cloudflare: '.$e->getMessage());
        }

        // ponytail: first account the token can see; add a picker when someone authorizes several accounts.
        $account = $accounts[0] ?? null;
        if (! $account) {
            return $this->back('The authorization did not grant access to any Cloudflare account. Check the client scopes.');
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

        return redirect()->route('security.cloudflare')->with('success', 'Cloudflare account '.($account['name'] ?? $account['id']).' connected.');
    }

    private function back(string $error): RedirectResponse
    {
        return redirect()->route('security.cloudflare')->with('error', $error);
    }
}
