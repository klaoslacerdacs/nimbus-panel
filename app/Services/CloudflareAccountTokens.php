<?php

namespace App\Services;

use App\Models\CloudflareConnection;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Creates Account API tokens through the team's Cloudflare OAuth connection, so the user never copies a token
 * by hand. The OAuth client needs the `account_api_tokens:create` scope (Cloudflare, 2026-10).
 */
class CloudflareAccountTokens
{
    public const string SCOPE = 'account_api_tokens:create';

    /**
     * Permission groups per capability, as Cloudflare names them ("Write" in the API, "Edit" in the dashboard).
     *
     * @var array<string, array<int, string>>
     */
    private const PERMISSIONS = [
        'dns' => ['DNS Write', 'Zone Read'],
        'tunnel' => ['Cloudflare Tunnel Write'],
    ];

    /**
     * @param  array<int, string>  $capabilities
     * @return string The token value, shown by Cloudflare only once.
     */
    public function create(CloudflareConnection $connection, string $name, array $capabilities): string
    {
        $account = $connection->account_id;

        try {
            $groups = collect($connection->api()->get("/accounts/{$account}/tokens/permission_groups")->throw()->json('result') ?? []);
        } catch (RequestException $e) {
            throw new RuntimeException($this->scopeHint($e));
        }

        $wanted = collect(self::PERMISSIONS)->only($capabilities)->flatten()->unique();
        $ids = $wanted->mapWithKeys(function (string $permission) use ($groups): array {
            $group = $groups->first(fn (array $group): bool => in_array(strtolower($group['name'] ?? ''), [
                strtolower($permission),
                strtolower(str_replace('Write', 'Edit', $permission)),
            ], true));
            if ($group === null) {
                throw new RuntimeException("The connected Cloudflare account does not offer the \"{$permission}\" permission to API tokens.");
            }

            return [$permission => $group['id']];
        });

        $zoneResources = ["com.cloudflare.api.account.{$account}" => ['com.cloudflare.api.account.zone.*' => '*']];
        $accountResources = ["com.cloudflare.api.account.{$account}" => '*'];
        $policies = [[
            'effect' => 'allow',
            'resources' => $zoneResources,
            'permission_groups' => $ids->only(self::PERMISSIONS['dns'])->values()->map(fn (string $id): array => ['id' => $id])->all(),
        ]];
        if (in_array('tunnel', $capabilities, true)) {
            $policies[] = [
                'effect' => 'allow',
                'resources' => $accountResources,
                'permission_groups' => $ids->only(self::PERMISSIONS['tunnel'])->values()->map(fn (string $id): array => ['id' => $id])->all(),
            ];
        }

        try {
            $value = $connection->api()->post("/accounts/{$account}/tokens", ['name' => $name, 'policies' => $policies])->throw()->json('result.value');
        } catch (RequestException $e) {
            throw new RuntimeException($this->scopeHint($e));
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Cloudflare created the token but did not return its value.');
        }

        return $value;
    }

    private function scopeHint(RequestException $e): string
    {
        $message = $e->response->json('errors.0.message') ?? 'HTTP '.$e->response->status();

        return "Cloudflare refused to create the token ({$message}). The OAuth client needs the ".self::SCOPE.' scope and the connected user the API Token Provisioning role; reconnect Cloudflare in Security → Cloudflare after adding them.';
    }
}
