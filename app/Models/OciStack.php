<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OciStack extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory;

    protected $fillable = [
        'server_id',
        'team_id',
        'oci_connection_id',
        'name',
        'stack_ocid',
        'compartment_ocid',
        'vcn_ocid',
        'nsg_ocid',
        'firewall_rules',
        'region',
        'tf_vars',
        'status',
        'last_plan_summary',
        'oci_instance_id',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'tf_vars' => 'array',
            'firewall_rules' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function ociConnection(): BelongsTo
    {
        return $this->belongsTo(OciConnection::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'planning', 'applying', 'destroying']);
    }

    public static function ownedByTeam(int $teamId): Builder
    {
        return self::query()->where('team_id', $teamId);
    }

    public static function ownedByCurrentTeam(array $select = ['*']): Builder
    {
        return self::ownedByTeam(currentTeam()->id)->select($select);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(OciStackJob::class)->latest('id');
    }

    /**
     * Firewall rules in the canonical shape the Terraform template expects (stable keys, explicit port ranges).
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return list<array{protocol: string, port_min?: int, port_max?: int, source: string, description: string}>
     */
    public static function normalizeFirewallRules(array $rules): array
    {
        $normalized = [];
        foreach ($rules as $rule) {
            $protocol = strtoupper((string) ($rule['protocol'] ?? 'TCP'));
            $entry = ['protocol' => $protocol, 'source' => (string) ($rule['source'] ?? '0.0.0.0/0'), 'description' => (string) ($rule['description'] ?? '')];
            $portMin = $rule['port_min'] ?? null;
            if (in_array($protocol, ['TCP', 'UDP'], true) && $portMin !== null && $portMin !== '') {
                $entry['port_min'] = (int) $portMin;
                $entry['port_max'] = (int) (($rule['port_max'] ?? '') !== '' && $rule['port_max'] !== null ? $rule['port_max'] : $portMin);
            }
            $normalized[self::firewallRuleKey($entry)] = $entry;
        }

        return array_values($normalized);
    }

    /**
     * Same key the Terraform template uses for the rule resource.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function firewallRuleKey(array $rule): string
    {
        return $rule['protocol'].'-'.($rule['port_min'] ?? 'any').'-'.($rule['port_max'] ?? 'any').'-'.$rule['source'];
    }

    /**
     * Resource Manager variables for this stack, with the firewall rules as the template's JSON variable.
     *
     * @return array<string, string>
     */
    public function terraformVariables(): array
    {
        return array_map('strval', [
            'tenancy_ocid' => $this->ociConnection?->tenancy_ocid,
            ...($this->tf_vars ?? []),
            'ingress_rules_json' => json_encode(self::normalizeFirewallRules($this->firewall_rules ?? []), JSON_UNESCAPED_SLASHES),
        ]);
    }
}
