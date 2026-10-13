<?php

namespace App\Models;

use App\Jobs\OciStackFirewallJob;
use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
        'attached_nsg_ids',
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
            'attached_nsg_ids' => 'array',
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
    public function terraformVariables(?array $effectiveRules = null): array
    {
        return array_map('strval', [
            'tenancy_ocid' => $this->ociConnection?->tenancy_ocid,
            ...($this->tf_vars ?? []),
            'ingress_rules_json' => json_encode($effectiveRules ?? $this->effectiveFirewallRules(), JSON_UNESCAPED_SLASHES),
            'extra_nsg_ids_json' => json_encode(array_values($this->attached_nsg_ids ?? [])),
        ]);
    }

    public function firewallPolicies(): BelongsToMany
    {
        return $this->belongsToMany(OciFirewallPolicy::class);
    }

    /**
     * What Terraform applies: the VM's own rules plus every attached policy, deduplicated by rule key.
     *
     * @return list<array<string, mixed>>
     */
    public function effectiveFirewallRules(): array
    {
        return self::normalizeFirewallRules([
            ...($this->firewall_rules ?? []),
            ...$this->firewallPolicies()->get()->flatMap(fn (OciFirewallPolicy $policy): array => $policy->rules ?? [])->all(),
        ]);
    }

    /**
     * Validation for one firewall rule under $prefix (e.g. "newRule"), shared by the VM and policy editors.
     *
     * @return array<string, mixed>
     */
    public static function firewallRuleValidation(string $prefix): array
    {
        return [
            "{$prefix}.protocol" => 'required|in:TCP,UDP,ICMP,ALL',
            "{$prefix}.port_min" => "nullable|required_if:{$prefix}.protocol,TCP,UDP|integer|min:1|max:65535",
            "{$prefix}.port_max" => "nullable|integer|min:1|max:65535|gte:{$prefix}.port_min",
            "{$prefix}.source" => ['required', 'string', 'regex:/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)\/(3[0-2]|[12]?\d)$/'],
            "{$prefix}.description" => 'nullable|string|max:255',
        ];
    }

    /** OCI limit of security rules per network security group. */
    public const int MAX_NSG_RULES = 120;

    /**
     * Set from queueing until the firewall job finishes, covering the gap before its first job record exists.
     */
    public function pendingFirewallKey(): string
    {
        return "nimbus:oci-firewall-pending:{$this->id}";
    }

    public function hasRunningJob(): bool
    {
        return Cache::has($this->pendingFirewallKey()) || $this->jobs()->whereIn('status', OciStackJob::RUNNING)->get()->contains(fn (OciStackJob $job): bool => $job->isRunning());
    }

    /**
     * Set the desired firewall and let Terraform apply it (plan → apply).
     * null keeps the VM's own rules and re-applies after a policy change.
     *
     * @param  array<int, array<string, mixed>>|null  $rules
     * @return 'queued'|'busy'|'too_many_rules'|'not_managed'
     */
    public function queueFirewallChange(?array $rules = null, ?array $attachedNsgIds = null): string
    {
        if ($this->status !== 'provisioned' || ! $this->stack_ocid) {
            return 'not_managed';
        }

        // Check-and-set under a lock so two quick changes cannot both pass the "busy" check.
        return Cache::lock("nimbus:oci-firewall-lock:{$this->id}", 10)->block(5, function () use ($rules, $attachedNsgIds): string {
            if ($this->hasRunningJob()) {
                return 'busy';
            }

            $own = $rules === null ? ($this->firewall_rules ?? []) : self::normalizeFirewallRules($rules);
            $policyRules = $this->firewallPolicies()->get()->flatMap(fn (OciFirewallPolicy $policy): array => $policy->rules ?? [])->all();
            if (count(self::normalizeFirewallRules([...$own, ...$policyRules])) > self::MAX_NSG_RULES) {
                return 'too_many_rules';
            }

            if ($rules !== null) {
                $this->update(['firewall_rules' => $own]);
            }
            if ($attachedNsgIds !== null) {
                $this->update(['attached_nsg_ids' => array_values(array_unique(array_diff($attachedNsgIds, [$this->nsg_ocid])))]);
            }
            Cache::put($this->pendingFirewallKey(), true, 3900);
            OciStackFirewallJob::dispatch($this);

            return 'queued';
        });
    }

    /**
     * Message for a queueFirewallChange() result that did not queue.
     */
    public static function firewallChangeError(string $result): string
    {
        return match ($result) {
            'busy' => 'Terraform is still applying the previous change. Try again when it finishes.',
            'too_many_rules' => 'OCI allows at most '.self::MAX_NSG_RULES.' rules per security group (including shared policies).',
            default => 'This VM is not managed by Terraform.',
        };
    }

    /**
     * Firewall versions: rule snapshots of successful applies, newest first.
     *
     * @return Collection<int, OciStackJob>
     */
    public function firewallVersions(int $limit = 20): Collection
    {
        return $this->jobs()->where('operation', 'apply')->where('status', 'succeeded')->whereNotNull('firewall_rules')->limit($limit)->get();
    }
}
