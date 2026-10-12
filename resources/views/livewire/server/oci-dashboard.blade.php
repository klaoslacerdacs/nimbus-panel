<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > OCI | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-[1180px] min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="oci" />
        <div class="application-settings-form flex flex-col gap-6 min-w-0 w-full">

    {{-- Provisioning / Destroy Progress --}}
    @php
        $isTerminalError = in_array($stackStatus, ['failed', 'destroy_failed']);
        $isDestroying = $stackStatus === 'destroying';
        $showProgress = $isProvisioning || $isDestroying || $isTerminalError;
        $isFailed = $isTerminalError;
    @endphp
    @if ($showProgress)
        @php
            $steps = [
                'pending'  => 0,
                'planning' => 1,
                'applying' => 2,
                'failed'   => 3,
            ];
            $currentIdx = $steps[$stackStatus] ?? ($isDestroying ? 1 : 0);
        @endphp
        <x-application.settings-section :title="$isDestroying ? 'Destroying resources' : ($isFailed ? 'Operation failed' : 'Provisioning in progress')">
            <div @if ($isProvisioning || $isDestroying) wire:poll.5000ms="loadProvisioningStatus" @endif>
                @if (! $isDestroying)
                    <div class="mb-4 flex items-center gap-1">
                        @foreach (['Queued', 'Planning', 'Applying', $isFailed ? 'Failed' : 'Done'] as $stepIdx => $stepLabel)
                            @php
                                $done = $stepIdx < $currentIdx;
                                $active = $stepIdx === $currentIdx;
                            @endphp
                            <div class="flex items-center">
                                <div @class([
                                    'flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-medium',
                                    'bg-success text-white' => $done,
                                    'bg-error text-white' => $active && $isFailed,
                                    'bg-coollabs text-white dark:bg-warning dark:text-black' => $active && ! $isFailed,
                                    'bg-neutral-200 text-neutral-500 dark:bg-white/[0.08] dark:text-fg-dim' => ! $done && ! $active,
                                ])>
                                    @if ($done) ✓
                                    @elseif ($active && $isFailed) ✕
                                    @else {{ $stepIdx + 1 }}
                                    @endif
                                </div>
                                <span class="ml-1 text-[12px] {{ $active ? 'font-semibold' : 'text-neutral-500 dark:text-fg-dim' }}">{{ $stepLabel }}</span>
                                @if ($stepIdx < 3)
                                    <div class="mx-2 h-px w-6 {{ $done ? 'bg-success/40' : 'bg-neutral-200 dark:bg-white/[0.08]' }}"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($stackMessage)
                    <pre class="overflow-x-auto whitespace-pre-wrap break-words text-[12px] {{ $isFailed ? 'text-error' : 'text-neutral-500 dark:text-fg-dim' }}">{{ $stackMessage }}</pre>
                @endif
            </div>
        </x-application.settings-section>
    @endif

    {{-- Instance Status --}}
    <x-application.settings-section title="Instance status" wire:poll.30000ms="loadStatus">
        <x-slot:actions>
            @if ($server->oci_instance_id)
                @php $state = $instanceStatus['lifecycle_state'] ?? $server->oci_instance_status ?? 'UNKNOWN'; @endphp
                @if ($confirmingStop)
                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Stop instance?</span>
                    <button wire:click="stopInstance" class="button button-danger">Confirm</button>
                    <button wire:click="cancelConfirm" class="button">Cancel</button>
                @elseif ($confirmingReboot)
                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Reboot instance?</span>
                    <button wire:click="rebootInstance" class="button button-danger">Confirm</button>
                    <button wire:click="cancelConfirm" class="button">Cancel</button>
                @else
                    <button wire:click="startInstance" wire:loading.attr="disabled" @disabled($state === 'RUNNING') class="button">Start</button>
                    <button wire:click="confirmStop" wire:loading.attr="disabled" @disabled($state !== 'RUNNING') class="button">Stop</button>
                    <button wire:click="confirmReboot" wire:loading.attr="disabled" @disabled($state !== 'RUNNING') class="button">Reboot</button>
                @endif
            @endif
        </x-slot:actions>

        @if (! $server->oci_instance_id)
            <x-empty size="sm" title="Not yet provisioned"
                description="{{ $isProvisioning ? 'Waiting for provisioning to complete.' : 'No OCI instance linked to this server.' }}">
                <x-slot:icon><x-reicon name="server" class="size-8" /></x-slot:icon>
            </x-empty>
        @else
            @php
                $state = $instanceStatus['lifecycle_state'] ?? $server->oci_instance_status ?? 'UNKNOWN';
                $badgeType = match (true) {
                    $state === 'RUNNING' => 'success',
                    in_array($state, ['STARTING', 'STOPPING', 'PROVISIONING']) => 'warning',
                    default => 'info',
                };
            @endphp
            <x-status-badge :label="$state" :type="$badgeType" />
        @endif
    </x-application.settings-section>

    {{-- Metrics --}}
    <x-application.settings-section title="Metrics" helper="5-minute average from OCI Monitoring.">
        @if ($metrics)
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div>
                    <p class="text-[11px] text-neutral-500 dark:text-fg-dim">CPU</p>
                    <p class="text-xl font-semibold">{{ $metrics['cpu_percent'] !== null ? $metrics['cpu_percent'].'%' : '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-neutral-500 dark:text-fg-dim">Memory</p>
                    <p class="text-xl font-semibold">{{ $metrics['memory_percent'] !== null ? $metrics['memory_percent'].'%' : '-' }}</p>
                    @if ($metrics['memory_percent'] === null)
                        <p class="text-[11px] text-neutral-400 dark:text-fg-faint">OCI agent required</p>
                    @endif
                </div>
                <div>
                    <p class="text-[11px] text-neutral-500 dark:text-fg-dim">Network in</p>
                    <p class="text-xl font-semibold">{{ $metrics['net_bytes_in'] !== null ? number_format($metrics['net_bytes_in'] / 1024, 1).' KB/s' : '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-neutral-500 dark:text-fg-dim">Network out</p>
                    <p class="text-xl font-semibold">{{ $metrics['net_bytes_out'] !== null ? number_format($metrics['net_bytes_out'] / 1024, 1).' KB/s' : '-' }}</p>
                </div>
            </div>
        @else
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">No metrics available. Instance may not be running or OCI Monitoring may not be enabled.</p>
        @endif
    </x-application.settings-section>

    {{-- Firewall: NSGs on the VM's VNIC + subnet security lists --}}
    @php $ociStack = $server->ociStack; @endphp
    @if ($firewall)
        <x-application.settings-section title="Firewall"
            helper="Inbound traffic is allowed when any security group (NSG) on this VM or any security list of its subnet allows it." flush>
            <x-slot:actions>
                @if ($terraformStack || ($firewall['nsgs'] ?? []))
                    <button wire:click="addNsgRule" wire:loading.attr="disabled" @disabled($firewallApplying) class="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Add rule
                    </button>
                @else
                    <button wire:click="createNsg" wire:loading.attr="disabled" class="button button-highlighted">
                        <x-reicon name="shield" class="size-3.5" />
                        Create security group for this VM
                    </button>
                @endif
            </x-slot:actions>

            @if ($terraformStack || ($firewall['nsgs'] ?? []))
                <div class="grid gap-3 border-b border-neutral-200 p-4 dark:border-white/[0.08]" @if ($firewallApplying) wire:poll.5000ms @endif>
                    @if ($terraformStack)
                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[12px] dark:border-white/[0.08] dark:bg-white/[0.03]">
                            <span class="font-medium">Managed by Terraform.</span>
                            Each change updates the stack and runs plan → apply, versioned in
                            <a href="{{ route('security.oci-jobs', ['stack' => $terraformStack->uuid]) }}" class="underline" {{ wireNavigate() }}>Terraform Jobs</a>.
                            @if ($firewallApplying)
                                <span class="ml-1 font-medium text-warning">Applying the last change…</span>
                            @endif
                        </div>
                    @elseif (count($firewall['nsgs']) > 1)
                        <div class="max-w-md">
                            <x-forms.listbox id="selectedNsgId" label="Security group" live
                                :options="collect($firewall['nsgs'])->map(fn ($nsg) => ['value' => $nsg['id'], 'label' => $nsg['display_name'] ?? $nsg['id']])->all()" />
                        </div>
                    @else
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                            Security group: <span class="font-mono">{{ $firewall['nsgs'][0]['display_name'] ?? $firewall['nsgs'][0]['id'] }}</span>
                            · this VM is not managed by Terraform, so changes apply directly and are not versioned.
                        </p>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 text-[12px]">
                        <span class="text-neutral-500 dark:text-fg-dim">Quick add:</span>
                        <button type="button" wire:click="applyRulePreset('http')" class="button button-secondary text-xs">HTTP 80</button>
                        <button type="button" wire:click="applyRulePreset('https')" class="button button-secondary text-xs">HTTPS 443</button>
                        <button type="button" wire:click="applyRulePreset('ssh')" class="button button-secondary text-xs">SSH 22</button>
                    </div>

                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-24 flex-1">
                            <x-forms.listbox id="newRule.protocol" label="Protocol" :options="[
                                ['value' => 'TCP', 'label' => 'TCP'],
                                ['value' => 'UDP', 'label' => 'UDP'],
                                ['value' => 'ICMP', 'label' => 'ICMP'],
                                ['value' => 'ALL', 'label' => 'ALL'],
                            ]" />
                        </div>
                        <div class="w-28">
                            <x-forms.input id="newRule.port_min" type="number" label="Port" placeholder="8080" min="1" max="65535" />
                        </div>
                        <div class="w-28">
                            <x-forms.input id="newRule.port_max" type="number" label="To port (optional)" placeholder="8090" min="1" max="65535" />
                        </div>
                        <div class="w-40">
                            <x-forms.input id="newRule.source" label="Source CIDR" placeholder="0.0.0.0/0" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <x-forms.input id="newRule.description" label="Description" placeholder="Optional" />
                        </div>
                    </div>
                    @foreach (['newRule.port_min', 'newRule.port_max', 'newRule.source'] as $field)
                        @error($field) <p class="text-[12px] text-error">{{ $message }}</p> @enderror
                    @endforeach
                </div>

                @php $rulesToShow = $terraformStack ? $managedRules : $nsgRules; @endphp
                @if ($rulesToShow)
                    <div class="data-table">
                        <div class="data-table-header oci-nsg-grid">
                            <span>Protocol</span>
                            <span>Port</span>
                            <span>Source</span>
                            <span>Description</span>
                            <span class="text-right">Actions</span>
                        </div>
                        @foreach ($rulesToShow as $rule)
                            <div wire:key="nsg-rule-{{ $rule['id'] }}" class="data-table-row oci-nsg-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                <div class="font-mono text-[12px]">{{ $rule['protocol_label'] ?? $rule['protocol'] }}</div>
                                <div class="font-mono text-[12px]">
                                    @if (isset($rule['port_min']))
                                        {{ $rule['port_min'] }}{{ isset($rule['port_max']) && $rule['port_max'] !== $rule['port_min'] ? '-'.$rule['port_max'] : '' }}
                                    @else
                                        all
                                    @endif
                                </div>
                                <div class="font-mono text-[12px]">{{ $rule['source'] ?? '-' }}</div>
                                <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $rule['description'] ?: '-' }}</div>
                                <div class="flex justify-end">
                                    <button wire:click="removeNsgRule('{{ $rule['id'] }}')" wire:loading.attr="disabled" @disabled($firewallApplying)
                                        class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-medium text-error transition-colors hover:bg-error/10">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-empty size="sm" title="No ingress rules" description="Add a rule above to allow traffic to this instance.">
                        <x-slot:icon><x-reicon name="shield" class="size-8" /></x-slot:icon>
                    </x-empty>
                @endif
            @else
                <p class="p-4 text-[12px] text-neutral-500 dark:text-fg-dim">
                    This VM has no security group. Create one to open ports for this VM only, without touching the subnet security list shared with other VMs.
                </p>
            @endif

            @if ($terraformStack)
                <div class="border-t border-neutral-200 px-4 pt-3 pb-3 dark:border-white/[0.08]">
                    <p class="text-[12px] font-medium">Shared firewall policies
                        <a href="{{ route('security.oci-firewall-policies') }}" class="font-normal underline" {{ wireNavigate() }}>manage</a>
                    </p>
                    @forelse ($attachedPolicies as $policy)
                        <div wire:key="vm-policy-{{ $policy->id }}" class="mt-1 flex items-center justify-between gap-2 text-[12px]">
                            <span>
                                <span class="font-medium">{{ $policy->name }}</span>
                                <span class="font-mono text-neutral-500 dark:text-fg-dim">{{ collect($policy->rules)->map(fn ($rule) => $rule['protocol'].' '.($rule['port_min'] ?? 'all').' from '.$rule['source'])->implode(' · ') }}</span>
                            </span>
                            <button type="button" wire:click="toggleFirewallPolicy({{ $policy->id }})" @disabled($firewallApplying) class="text-[11px] font-medium text-error">Detach</button>
                        </div>
                    @empty
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">No shared policy attached; only this VM's own rules apply.</p>
                    @endforelse
                    @php $attachable = $teamPolicies->whereNotIn('id', $attachedPolicies->pluck('id')); @endphp
                    @if ($attachable->isNotEmpty())
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
                            <span class="text-neutral-500 dark:text-fg-dim">Attach:</span>
                            @foreach ($attachable as $policy)
                                <button type="button" wire:key="vm-attach-{{ $policy->id }}" wire:click="toggleFirewallPolicy({{ $policy->id }})" @disabled($firewallApplying) class="button button-secondary text-xs">{{ $policy->name }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($terraformStack && $firewallVersions->isNotEmpty())
                @php $currentKeys = collect($managedRules)->pluck('id')->sort()->values()->all(); @endphp
                <div class="border-t border-neutral-200 px-4 pt-3 pb-3 dark:border-white/[0.08]">
                    <p class="text-[12px] font-medium">Firewall versions <span class="font-normal text-neutral-500 dark:text-fg-dim">(successful Terraform applies, newest first)</span></p>
                    <ul class="mt-2 flex flex-col gap-1.5">
                        @foreach ($firewallVersions as $version)
                            @php
                                $versionKeys = collect($version->firewall_rules)->map(fn ($rule) => App\Models\OciStack::firewallRuleKey($rule));
                                $isCurrent = $versionKeys->sort()->values()->all() === $currentKeys;
                                $added = $versionKeys->diff($currentKeys)->count();
                                $removed = collect($currentKeys)->diff($versionKeys)->count();
                            @endphp
                            <li wire:key="fw-version-{{ $version->id }}" class="flex flex-wrap items-center justify-between gap-2 text-[12px]">
                                <span>
                                    <span class="font-mono">{{ $version->finished_at?->format('Y-m-d H:i') }}</span>
                                    · {{ count($version->firewall_rules) }} rules
                                    <span class="text-neutral-500 dark:text-fg-dim">
                                        ({{ collect($version->firewall_rules)->map(fn ($rule) => $rule['protocol'].' '.($rule['port_min'] ?? 'all'))->implode(', ') }})
                                    </span>
                                    @if ($isCurrent)
                                        <x-status-badge label="Current" type="success" />
                                    @else
                                        <span class="text-neutral-500 dark:text-fg-dim">· restoring {{ $added ? '+'.$added : '' }}{{ $added && $removed ? ' / ' : '' }}{{ $removed ? '−'.$removed : '' }} rules</span>
                                    @endif
                                </span>
                                @unless ($isCurrent)
                                    <button type="button" wire:click="restoreFirewallVersion({{ $version->id }})" wire:loading.attr="disabled" @disabled($firewallApplying)
                                        class="button button-secondary text-xs">Restore</button>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @foreach ($firewall['security_lists'] ?? [] as $list)
                <div class="border-t border-neutral-200 px-4 pt-3 pb-2 dark:border-white/[0.08]">
                    <p class="text-[12px] font-medium">Subnet security list · {{ $list['display_name'] ?? $list['id'] }} <span class="font-normal text-neutral-500 dark:text-fg-dim">(read-only, shared by every VM in the subnet)</span></p>
                    @if ($list['rules'])
                        <ul class="mt-1 space-y-0.5 font-mono text-[12px] text-neutral-600 dark:text-neutral-300">
                            @foreach ($list['rules'] as $rule)
                                <li>
                                    {{ $rule['protocol_label'] ?? $rule['protocol'] }}
                                    {{ isset($rule['port_min']) ? $rule['port_min'].(($rule['port_max'] ?? null) !== $rule['port_min'] ? '-'.$rule['port_max'] : '') : 'all ports' }}
                                    from {{ $rule['source'] ?? '-' }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">No ingress rules.</p>
                    @endif
                </div>
            @endforeach
        </x-application.settings-section>
    @endif

    {{-- Block Volumes --}}
    @if ($server->oci_instance_id && $server->oci_compartment_id)
        <x-application.settings-section title="Block volumes" flush>
            <x-slot:actions>
                @if (! $showCreateVolume && ! $showVolumeSelector)
                    <button wire:click="loadAvailableVolumes" class="button">
                        <x-reicon name="plus" class="size-3.5" />
                        Attach existing
                    </button>
                    <button wire:click="$set('showCreateVolume', true)" class="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Create new
                    </button>
                @endif
            </x-slot:actions>

            @if ($confirmingDetach)
                <div class="flex items-center gap-3 border-b border-neutral-200 bg-error/5 px-4 py-3 dark:border-white/[0.08]">
                    <span class="min-w-0 flex-1 text-[12px] text-error">Detach this volume? The instance will lose access immediately.</span>
                    <button wire:click="detachVolume" class="button button-danger">Confirm detach</button>
                    <button wire:click="cancelConfirm" class="button">Cancel</button>
                </div>
            @endif

            @if ($showCreateVolume)
                <div class="flex flex-wrap items-start gap-2 border-b border-neutral-200 p-4 dark:border-white/[0.08]">
                    <div class="min-w-0 flex-1">
                        <x-forms.input id="newVolumeName" label="Volume name" placeholder="my-data-volume" />
                    </div>
                    <div class="w-32">
                        <x-forms.input id="newVolumeSize" type="number" label="Size (GB)" min="50" max="32768" />
                    </div>
                    <div class="flex items-end gap-2 self-end pb-0.5">
                        <button wire:click="createVolume" class="button button-highlighted">Create</button>
                        <button wire:click="$set('showCreateVolume', false)" class="button">Cancel</button>
                    </div>
                </div>
                @error('newVolumeName') <p class="px-4 pb-2 text-[12px] text-error">{{ $message }}</p> @enderror
                @error('newVolumeSize') <p class="px-4 pb-2 text-[12px] text-error">{{ $message }}</p> @enderror
            @elseif ($showVolumeSelector)
                @php
                    $volumeOptions = collect($availableVolumes)->map(fn ($v) => [
                        'value' => $v['id'],
                        'label' => ($v['display_name'] ?? $v['id']).($v['size_in_gbs'] ? ' ('.$v['size_in_gbs'].' GB)' : ''),
                    ])->all();
                @endphp
                <div class="flex flex-wrap items-end gap-2 border-b border-neutral-200 p-4 dark:border-white/[0.08]">
                    @if ($availableVolumes)
                        <div class="min-w-0 flex-1">
                            <x-forms.listbox id="attachVolumeOcid" label="Select volume" :options="$volumeOptions" placeholder="Select a volume" />
                        </div>
                        <div class="flex gap-2 pb-0.5">
                            <button wire:click="attachVolume" class="button button-highlighted" :disabled="! $attachVolumeOcid">Attach</button>
                            <button wire:click="$set('showVolumeSelector', false)" class="button">Cancel</button>
                        </div>
                    @else
                        <p class="flex-1 text-[12px] text-neutral-500 dark:text-fg-dim">No available volumes in this compartment.</p>
                        <button wire:click="$set('showVolumeSelector', false)" class="button">Cancel</button>
                    @endif
                </div>
            @endif

            @if ($volumeAttachments)
                <div class="data-table">
                    <div class="data-table-header oci-volumes-grid">
                        <span>Volume OCID</span>
                        <span>Type</span>
                        <span>Size</span>
                        <span>State</span>
                        <span>Read-only</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @foreach ($volumeAttachments as $attachment)
                        <div wire:key="volume-{{ $attachment['id'] }}" class="data-table-row oci-volumes-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="min-w-0 truncate font-mono text-[12px] text-neutral-500 dark:text-fg-dim">{{ Str::limit($attachment['volume_id'], 28) }}</div>
                            <div class="text-[12px]">{{ $attachment['attachment_type'] ?? '-' }}</div>
                            <div class="font-mono text-[12px]">{{ isset($attachment['size_in_gbs']) && $attachment['size_in_gbs'] ? $attachment['size_in_gbs'].' GB' : '-' }}</div>
                            @php
                                $volState = $attachment['lifecycle_state'];
                                $volBadge = match (true) {
                                    $volState === 'ATTACHED' => 'success',
                                    in_array($volState, ['ATTACHING', 'DETACHING']) => 'warning',
                                    $volState === 'DETACHED' => 'error',
                                    default => 'neutral',
                                };
                            @endphp
                            <div><x-status-badge :label="$volState" :type="$volBadge" /></div>
                            <div class="text-[12px]">{{ $attachment['is_read_only'] ? 'Yes' : 'No' }}</div>
                            <div class="flex justify-end">
                                @if ($attachment['attachment_type'] !== 'boot')
                                    <button wire:click="confirmDetachVolume('{{ $attachment['id'] }}')"
                                        class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-medium text-error transition-colors hover:bg-error/10">
                                        Detach
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <x-empty size="sm" title="No block volumes attached" description="Attach an existing volume or create a new one.">
                    <x-slot:icon><x-reicon name="database" class="size-8" /></x-slot:icon>
                </x-empty>
            @endif
        </x-application.settings-section>
    @endif

    {{-- Load Balancers --}}
    @if ($server->oci_compartment_id && $loadBalancers)
        <x-application.settings-section title="Load balancers" flush>
            <div class="data-table">
                <div class="data-table-header oci-lb-grid">
                    <span>Name</span>
                    <span>Shape</span>
                    <span>IPs</span>
                    <span>Backend sets</span>
                    <span>State</span>
                </div>
                @foreach ($loadBalancers as $lb)
                    <div wire:key="lb-{{ $lb['id'] }}" class="data-table-row oci-lb-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <div class="text-[12px] font-medium">{{ $lb['display_name'] }}</div>
                        <div class="font-mono text-[12px] text-neutral-500 dark:text-fg-dim">{{ $lb['shape_name'] }}</div>
                        <div class="font-mono text-[12px]">{{ implode(', ', $lb['ip_addresses']) ?: '-' }}</div>
                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ implode(', ', $lb['backend_sets']) ?: '-' }}</div>
                        <div><x-status-badge :label="$lb['lifecycle_state']" :type="$lb['lifecycle_state'] === 'ACTIVE' ? 'success' : ($lb['lifecycle_state'] === 'FAILED' ? 'error' : 'neutral')" /></div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif

    {{-- Route Tables --}}
    @if ($routeTables)
        <x-application.settings-section title="Route tables" helper="VCN route tables for this instance.">
            <div class="flex flex-col gap-4">
                @foreach ($routeTables as $rt)
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[12px] font-semibold text-black dark:text-fg">{{ $rt['display_name'] }}</span>
                            <x-status-badge :label="$rt['lifecycle_state']" :type="$rt['lifecycle_state'] === 'AVAILABLE' ? 'success' : 'neutral'" />
                        </div>
                        @if ($rt['route_rules'])
                            <div class="data-table">
                                <div class="data-table-header oci-routes-grid">
                                    <span>Destination</span>
                                    <span>Type</span>
                                    <span>Target</span>
                                </div>
                                @foreach ($rt['route_rules'] as $rule)
                                    <div class="data-table-row oci-routes-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                        <div class="font-mono text-[12px]">{{ $rule['destination'] }}</div>
                                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $rule['destination_type'] }}</div>
                                        <div class="min-w-0 truncate font-mono text-[12px] text-neutral-500 dark:text-fg-dim">{{ Str::limit($rule['network_entity_id'], 32) }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-[12px] text-neutral-400 dark:text-fg-faint">No route rules.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif

    {{-- Object Storage --}}
    @if ($buckets)
        <x-application.settings-section title="Object storage" flush>
            <div class="data-table">
                <div class="data-table-header oci-buckets-grid">
                    <span>Bucket</span>
                    <span>Tier</span>
                    <span>Objects</span>
                    <span>Size</span>
                </div>
                @foreach ($buckets as $bucket)
                    <div wire:key="bucket-{{ $bucket['name'] }}" class="data-table-row oci-buckets-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <div class="text-[12px] font-medium">{{ $bucket['name'] }}</div>
                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $bucket['storage_tier'] }}</div>
                        <div class="text-[12px]">{{ number_format($bucket['approximate_count'] ?? 0) }}</div>
                        <div class="text-[12px]">{{ $bucket['approximate_size'] ? number_format($bucket['approximate_size'] / 1048576, 1).' MB' : '-' }}</div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif

    {{-- Autonomous Databases --}}
    @if ($server->oci_compartment_id && $autonomousDatabases)
        <x-application.settings-section title="Autonomous databases">
            <div class="flex flex-col gap-4">
                @foreach ($autonomousDatabases as $adb)
                    <div class="rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-[12px] font-semibold text-black dark:text-fg">{{ $adb['display_name'] }}</span>
                            <x-status-badge :label="$adb['lifecycle_state']" :type="$adb['lifecycle_state'] === 'AVAILABLE' ? 'success' : ($adb['lifecycle_state'] === 'TERMINATED' ? 'error' : 'neutral')" />
                        </div>
                        <div class="mb-2 flex flex-wrap gap-4 text-[11px] text-neutral-500 dark:text-fg-dim">
                            <span>DB: {{ $adb['db_name'] }}</span>
                            <span>Workload: {{ $adb['db_workload'] }}</span>
                            <span>{{ $adb['cpu_core_count'] }} OCPUs</span>
                            <span>{{ $adb['data_storage_size_in_tbs'] }} TB</span>
                        </div>
                        @if ($adb['connection_strings'])
                            <details class="text-[12px]">
                                <summary class="cursor-pointer text-neutral-500 hover:text-neutral-700 dark:text-fg-dim dark:hover:text-fg">Connection strings</summary>
                                <div class="mt-2 flex flex-col gap-1">
                                    @foreach ($adb['connection_strings'] as $profile => $cs)
                                        <div class="flex gap-2">
                                            <span class="w-16 shrink-0 font-mono text-[11px] text-neutral-400 dark:text-fg-faint">{{ $profile }}</span>
                                            <span class="break-all font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $cs }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif

        </div>
    </div>
</div>
