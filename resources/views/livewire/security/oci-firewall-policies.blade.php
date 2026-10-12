<div>
    <x-slot:title>Firewall Policies | Coolify</x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Firewall Policies"
            description="Shared firewall rules for several Oracle Cloud VMs. A VM applies its own rules plus every attached policy, through Terraform (plan → apply), versioned in Terraform Jobs." flush>
            <x-slot:actions>
                @can('create', App\Models\OciFirewallPolicy::class)
                    <x-forms.button type="button" wire:click="create" class="button-highlighted">New policy</x-forms.button>
                @endcan
            </x-slot:actions>

            @if ($policies->isEmpty())
                <x-empty title="No firewall policies yet"
                    description="Create a policy such as 'Public web: 80/443' and attach it to the VMs that need it."
                    icon-name="shield" />
            @else
                <div class="flex flex-col">
                    @foreach ($policies as $policy)
                        <div wire:key="fw-policy-{{ $policy->id }}" class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.08]">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[13px] font-semibold">{{ $policy->name }}</span>
                                <span class="truncate text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ collect($policy->rules)->map(fn ($rule) => $rule['protocol'].' '.($rule['port_min'] ?? 'all').' from '.$rule['source'])->implode(' · ') ?: 'No rules' }}
                                    · {{ $policy->stacks_count }} VM(s)
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-forms.button type="button" wire:click="edit({{ $policy->id }})" class="button-secondary">Edit</x-forms.button>
                                @can('delete', $policy)
                                    <x-modal-confirmation title="Delete firewall policy?" isErrorButton buttonTitle="Delete"
                                        submitAction="delete({{ $policy->id }})" :actions="[
                                            'The policy is removed and every VM using it is re-applied through Terraform without its rules.',
                                        ]"
                                        confirmationText="{{ $policy->name }}" confirmationLabel="Enter the policy name to confirm"
                                        shortConfirmationLabel="Policy name" :confirmWithPassword="false" step2ButtonText="Delete policy" />
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>

        @if ($editing)
            <x-application.settings-section :title="$editingId ? 'Edit policy' : 'New policy'">
                <form wire:submit="save" class="flex flex-col gap-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <x-forms.input id="name" label="Name" required placeholder="Public web" />
                        <x-forms.input id="description" label="Description" placeholder="Optional" />
                    </div>

                    <x-forms.listbox id="stackUuids" label="Apply to VMs" multiple searchable portal
                        placeholder="Choose Terraform-managed VMs" :options="$stackOptions" />

                    <div>
                        <p class="mb-2 text-[12px] font-medium">Rules</p>
                        @forelse ($rules as $rule)
                            @php $ruleKey = App\Models\OciStack::firewallRuleKey($rule); @endphp
                            <div wire:key="policy-rule-{{ $ruleKey }}" class="flex items-center justify-between border-b border-neutral-200 py-1.5 font-mono text-[12px] last:border-b-0 dark:border-white/[0.07]">
                                <span>
                                    {{ $rule['protocol'] }} {{ isset($rule['port_min']) ? $rule['port_min'].($rule['port_max'] !== $rule['port_min'] ? '-'.$rule['port_max'] : '') : 'all' }}
                                    from {{ $rule['source'] }}
                                    <span class="font-sans text-neutral-500 dark:text-fg-dim">{{ $rule['description'] }}</span>
                                </span>
                                <button type="button" wire:click="removeRule('{{ $ruleKey }}')" class="text-[11px] font-medium text-error">Remove</button>
                            </div>
                        @empty
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">No rules yet.</p>
                        @endforelse
                    </div>

                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-24 flex-1">
                            <x-forms.listbox id="newRule.protocol" label="Protocol" :options="[
                                ['value' => 'TCP', 'label' => 'TCP'],
                                ['value' => 'UDP', 'label' => 'UDP'],
                                ['value' => 'ICMP', 'label' => 'ICMP'],
                                ['value' => 'ALL', 'label' => 'ALL'],
                            ]" />
                        </div>
                        <div class="w-28"><x-forms.input id="newRule.port_min" type="number" label="Port" placeholder="443" min="1" max="65535" /></div>
                        <div class="w-28"><x-forms.input id="newRule.port_max" type="number" label="To port" placeholder="optional" min="1" max="65535" /></div>
                        <div class="w-40"><x-forms.input id="newRule.source" label="Source CIDR" placeholder="0.0.0.0/0" /></div>
                        <div class="min-w-0 flex-1"><x-forms.input id="newRule.description" label="Description" placeholder="Optional" /></div>
                        <x-forms.button type="button" wire:click="addRule">Add rule</x-forms.button>
                    </div>
                    @foreach (['newRule.port_min', 'newRule.port_max', 'newRule.source'] as $field)
                        @error($field) <p class="text-[12px] text-error">{{ $message }}</p> @enderror
                    @endforeach

                    <div class="flex justify-end gap-2">
                        <x-forms.button type="button" wire:click="$set('editing', false)" class="button-bordered">Cancel</x-forms.button>
                        <x-forms.button type="submit" class="button-highlighted">Save and apply</x-forms.button>
                    </div>
                </form>
            </x-application.settings-section>
        @endif
    </x-security.settings-layout>
</div>
