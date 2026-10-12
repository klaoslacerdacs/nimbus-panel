<?php

namespace App\Livewire\Security;

use App\Models\OciFirewallPolicy;
use App\Models\OciStack;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Shared firewall rule sets: edit once, applied through Terraform on every attached VM.
 */
class OciFirewallPolicies extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    /** @var list<array<string, mixed>> */
    public array $rules = [];

    /** @var array<string, string|null> */
    public array $newRule = ['protocol' => 'TCP', 'port_min' => '', 'port_max' => '', 'source' => '0.0.0.0/0', 'description' => ''];

    /** @var list<string> */
    public array $stackUuids = [];

    public function mount(): void
    {
        $this->authorize('viewAny', OciFirewallPolicy::class);
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'description', 'rules', 'stackUuids', 'newRule');
        $this->editing = true;
    }

    public function edit(int $policyId): void
    {
        $policy = OciFirewallPolicy::ownedByCurrentTeam()->with('stacks:id,uuid')->findOrFail($policyId);
        $this->authorize('view', $policy);

        $this->editingId = $policy->id;
        $this->name = $policy->name;
        $this->description = (string) $policy->description;
        $this->rules = OciStack::normalizeFirewallRules($policy->rules ?? []);
        $this->stackUuids = $policy->stacks->pluck('uuid')->all();
        $this->editing = true;
    }

    public function addRule(): void
    {
        $this->validate(OciStack::firewallRuleValidation('newRule'), [
            'newRule.port_min.required_if' => 'TCP and UDP rules need a port.',
            'newRule.port_max.gte' => 'The last port must be greater than or equal to the first port.',
        ]);

        $this->rules = OciStack::normalizeFirewallRules([...$this->rules, array_filter($this->newRule, fn ($v): bool => $v !== '' && $v !== null)]);
        $this->reset('newRule');
    }

    public function removeRule(string $ruleKey): void
    {
        $this->rules = array_values(array_filter($this->rules, fn (array $rule): bool => OciStack::firewallRuleKey($rule) !== $ruleKey));
    }

    public function save(): void
    {
        $policy = $this->editingId ? OciFirewallPolicy::ownedByCurrentTeam()->with('stacks:id')->findOrFail($this->editingId) : null;
        $policy ? $this->authorize('update', $policy) : $this->authorize('create', OciFirewallPolicy::class);

        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'stackUuids' => 'array',
            'stackUuids.*' => 'string',
        ]);

        $teamId = $policy?->team_id ?? currentTeam()->id;
        $policy ??= new OciFirewallPolicy(['team_id' => $teamId]);
        $previousStackIds = $policy->exists ? $policy->stacks->pluck('id')->all() : [];

        $policy->fill(['name' => $this->name, 'description' => $this->description ?: null, 'rules' => OciStack::normalizeFirewallRules($this->rules)])->save();
        // Only this team's Terraform-managed VMs can be attached; anything else from the browser is ignored.
        $stackIds = $this->attachableStacks($teamId)->whereIn('uuid', $this->stackUuids)->pluck('id')->all();
        $policy->stacks()->sync($stackIds);

        auditLog('ui.oci_firewall_policy.saved', ['team_id' => $teamId, 'oci_firewall_policy_id' => $policy->id, 'stacks' => count($stackIds)]);
        $this->reapply(array_unique([...$previousStackIds, ...$stackIds]));
        $this->editing = false;
    }

    public function delete(int $policyId): void
    {
        $policy = OciFirewallPolicy::ownedByCurrentTeam()->with('stacks:id')->findOrFail($policyId);
        $this->authorize('delete', $policy);

        $stackIds = $policy->stacks->pluck('id')->all();
        $policy->delete();

        auditLog('ui.oci_firewall_policy.deleted', ['team_id' => $policy->team_id, 'oci_firewall_policy_id' => $policyId]);
        $this->reapply($stackIds);
    }

    /**
     * @param  array<int, int>  $stackIds
     */
    private function reapply(array $stackIds): void
    {
        $skipped = OciStack::query()->whereIn('id', $stackIds)->get()
            ->reject(fn (OciStack $stack): bool => $stack->queueFirewallChange())
            ->pluck('name');

        $this->dispatch('success', $skipped->isEmpty()
            ? 'Policy saved. Terraform is applying it on '.count($stackIds).' VM(s).'
            : 'Policy saved. Busy VMs to re-apply later: '.$skipped->implode(', ').'.');
    }

    /**
     * @return Collection<int, OciStack>
     */
    private function attachableStacks(int $teamId): Collection
    {
        return OciStack::query()->where('team_id', $teamId)->where('status', 'provisioned')->whereNotNull('stack_ocid')->orderBy('name')->get(['id', 'uuid', 'name']);
    }

    public function render(): Factory|View
    {
        return view('livewire.security.oci-firewall-policies', [
            'policies' => OciFirewallPolicy::ownedByCurrentTeam()->withCount('stacks')->orderBy('name')->get(),
            'stackOptions' => $this->attachableStacks(currentTeam()->id)->map(fn (OciStack $stack): array => ['value' => $stack->uuid, 'label' => $stack->name])->all(),
        ]);
    }
}
