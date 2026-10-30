<?php

namespace App\Services;

use App\Models\CloudflareConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Creates Account API tokens through the team's Cloudflare OAuth connection, so the user never copies a token
 * by hand. The OAuth client needs the "Account API Token Provisioning" permission (Cloudflare, 2026-10): dashboard
 * clients use the dot form `account-api-tokens.write`; the colon form `account_api_tokens:create` is only the first-party CLI client.
 */
class CloudflareAccountTokens
{
    public const string SCOPE = 'account-api-tokens.write';

    /**
     * Permission groups per capability. Cloudflare says "Write" in the API and "Edit" in the dashboard, and the
     * tunnel group kept its old "Argo Tunnel" name on some accounts; every alias of a group is accepted.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private const PERMISSIONS = [
        'dns' => [['DNS Write', 'DNS Edit'], ['Zone Read']],
        'tunnel' => [['Cloudflare Tunnel Write', 'Cloudflare Tunnel Edit', 'Argo Tunnel Write', 'Argo Tunnel Edit']],
    ];

    /**
     * @param  array<int, string>  $capabilities
     * @return array{id: string, value: string} The token id and its value, which Cloudflare shows only once.
     */
    public function create(CloudflareConnection $connection, string $name, array $capabilities): array
    {
        $account = $connection->account_id;

        try {
            $groups = collect($connection->api()->get("/accounts/{$account}/tokens/permission_groups")->throw()->json('result') ?? []);
        } catch (RequestException $e) {
            throw new RuntimeException($this->failureMessage($e));
        }

        $resources = [
            'dns' => ["com.cloudflare.api.account.{$account}" => ['com.cloudflare.api.account.zone.*' => '*']],
            'tunnel' => ["com.cloudflare.api.account.{$account}" => '*'],
        ];
        $policies = [];
        foreach (collect(self::PERMISSIONS)->only($capabilities) as $capability => $permissions) {
            $policies[] = [
                'effect' => 'allow',
                'resources' => $resources[$capability],
                'permission_groups' => array_map(fn (array $aliases): array => ['id' => $this->groupId($groups, $aliases)], $permissions),
            ];
        }

        try {
            $result = $connection->api()->post("/accounts/{$account}/tokens", ['name' => $name, 'policies' => $policies])->throw()->json('result') ?? [];
        } catch (RequestException $e) {
            throw new RuntimeException($this->failureMessage($e));
        }
        if (! is_string($result['value'] ?? null) || $result['value'] === '' || ! is_string($result['id'] ?? null)) {
            throw new RuntimeException('Cloudflare created the token but did not return its value.');
        }

        return ['id' => $result['id'], 'value' => $result['value']];
    }

    /**
     * Best effort: removes a token that could not be saved, so no unused credential stays active at Cloudflare.
     */
    public function delete(CloudflareConnection $connection, string $tokenId): void
    {
        rescue(fn () => $connection->api()->delete("/accounts/{$connection->account_id}/tokens/{$tokenId}"), report: false);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $aliases
     */
    private function groupId(Collection $groups, array $aliases): string
    {
        $wanted = array_map('strtolower', $aliases);
        $group = $groups->first(fn (array $group): bool => in_array(strtolower((string) ($group['name'] ?? '')), $wanted, true));
        if ($group === null) {
            throw new RuntimeException("The connected Cloudflare account does not offer the \"{$aliases[0]}\" permission to API tokens.");
        }

        return (string) $group['id'];
    }

    private function failureMessage(RequestException $e): string
    {
        $message = $e->response->json('errors.0.message') ?? 'HTTP '.$e->response->status();
        $hint = in_array($e->response->status(), [401, 403], true)
            ? ' The OAuth client needs the '.self::SCOPE.' scope and the connected user the API Token Provisioning role; reconnect Cloudflare in Security → Cloudflare after adding them.'
            : '';

        return "Cloudflare refused to create the token ({$message}).{$hint}";
    }
}
