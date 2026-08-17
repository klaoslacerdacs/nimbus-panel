<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > OCI | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-[1180px] min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="oci" />
        <div class="application-settings-form min-w-0 w-full">

<div class="flex flex-col gap-6" wire:poll.30000ms="loadStatus">

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
        <div @if ($isProvisioning || $isDestroying) wire:poll.5000ms="loadProvisioningStatus" @endif
            class="rounded-lg border {{ $isFailed ? 'border-red-300 dark:border-red-700' : ($isDestroying ? 'border-orange-200 dark:border-orange-700' : 'border-blue-200 dark:border-blue-700') }} p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">
                    @if ($isDestroying) Destroying Resources
                    @elseif ($isFailed) Operation Failed
                    @else Provisioning in Progress
                    @endif
                </h3>
                @if (! $isFailed)
                    <svg class="h-4 w-4 animate-spin {{ $isDestroying ? 'text-orange-500' : 'text-blue-500' }}" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                @endif
            </div>

            @if (! $isDestroying)
                <div class="mb-4 flex items-center gap-1">
                    @foreach (['Queued', 'Planning', 'Applying', $isFailed ? 'Failed' : 'Done'] as $stepIdx => $stepLabel)
                        @php
                            $done = $stepIdx < $currentIdx;
                            $active = $stepIdx === $currentIdx;
                        @endphp
                        <div class="flex items-center">
                            <div class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium
                                {{ $done ? 'bg-green-500 text-white' : ($active ? ($isFailed ? 'bg-red-500 text-white' : 'bg-blue-500 text-white') : 'bg-neutral-200 text-neutral-500 dark:bg-neutral-700') }}">
                                @if ($done) ✓
                                @elseif ($active && $isFailed) ✕
                                @else {{ $stepIdx + 1 }}
                                @endif
                            </div>
                            <span class="ml-1 text-xs {{ $active ? 'font-semibold' : 'text-neutral-500' }}">{{ $stepLabel }}</span>
                            @if ($stepIdx < 3)
                                <div class="mx-2 h-px w-6 {{ $done ? 'bg-green-400' : 'bg-neutral-200 dark:bg-neutral-700' }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($stackMessage)
                <pre class="overflow-x-auto whitespace-pre-wrap break-words text-xs {{ $isFailed ? 'text-red-600 dark:text-red-400' : 'text-neutral-500' }}">{{ $stackMessage }}</pre>
            @endif
        </div>
    @endif

    {{-- Instance Status --}}
    <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
        <h3 class="mb-3 text-sm font-semibold">Instance Status</h3>

        @if (! $server->oci_instance_id)
            <p class="text-sm text-neutral-500">
                @if ($isProvisioning)
                    Waiting for provisioning to complete...
                @else
                    Instance not yet provisioned.
                @endif
            </p>
        @else
            @php
                $state = $instanceStatus['lifecycle_state'] ?? $server->oci_instance_status ?? 'UNKNOWN';
                $color = match (true) {
                    $state === 'RUNNING' => 'bg-green-500',
                    in_array($state, ['STARTING', 'STOPPING', 'PROVISIONING']) => 'bg-yellow-500',
                    default => 'bg-neutral-400',
                };
            @endphp
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="inline-block h-2 w-2 rounded-full {{ $color }}"></span>
                    <span class="text-sm font-medium">{{ $state }}</span>
                </div>

                <div class="flex gap-2">
                    @if ($confirmingStop)
                        <span class="self-center text-xs text-neutral-500">Stop instance?</span>
                        <x-forms.button wire:click="stopInstance" class="button-danger px-2 py-1 text-xs">Confirm</x-forms.button>
                        <x-forms.button wire:click="cancelConfirm" class="px-2 py-1 text-xs">Cancel</x-forms.button>
                    @elseif ($confirmingReboot)
                        <span class="self-center text-xs text-neutral-500">Reboot instance?</span>
                        <x-forms.button wire:click="rebootInstance" class="button-danger px-2 py-1 text-xs">Confirm</x-forms.button>
                        <x-forms.button wire:click="cancelConfirm" class="px-2 py-1 text-xs">Cancel</x-forms.button>
                    @else
                        <x-forms.button wire:click="startInstance" :disabled="$actionLoading || $state === 'RUNNING'" class="px-3 py-1 text-xs">Start</x-forms.button>
                        <x-forms.button wire:click="confirmStop" :disabled="$actionLoading || $state !== 'RUNNING'" class="px-3 py-1 text-xs">Stop</x-forms.button>
                        <x-forms.button wire:click="confirmReboot" :disabled="$actionLoading || $state !== 'RUNNING'" class="px-3 py-1 text-xs">Reboot</x-forms.button>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Metrics --}}
    <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
        <h3 class="mb-3 text-sm font-semibold">Metrics (last 5 min avg)</h3>

        @if ($server->oci_metrics_source === 'sentinel')
            <p class="text-sm text-neutral-500">Metrics via SSH/Sentinel — ensure the Sentinel container is running on this server.</p>
        @elseif ($metrics)
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-neutral-500">CPU</p>
                    <p class="text-xl font-semibold">{{ $metrics['cpu_percent'] !== null ? $metrics['cpu_percent'].'%' : '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-neutral-500">Memory</p>
                    <p class="text-xl font-semibold">{{ $metrics['memory_percent'] !== null ? $metrics['memory_percent'].'%' : '—' }}</p>
                    @if ($metrics['memory_percent'] === null)
                        <p class="text-xs text-neutral-400">OCI agent required</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs text-neutral-500">Network In</p>
                    <p class="text-xl font-semibold">{{ $metrics['net_bytes_in'] !== null ? number_format($metrics['net_bytes_in'] / 1024, 1).' KB/s' : '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-neutral-500">Network Out</p>
                    <p class="text-xl font-semibold">{{ $metrics['net_bytes_out'] !== null ? number_format($metrics['net_bytes_out'] / 1024, 1).' KB/s' : '—' }}</p>
                </div>
            </div>
        @else
            <p class="text-sm text-neutral-500">No metrics available. Instance may not be running or OCI Monitoring may not be enabled.</p>
        @endif
    </div>

    {{-- NSG Firewall Rules --}}
    @php $ociStack = $server->ociStack; @endphp
    @if ($ociStack?->nsg_ocid)
        <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h3 class="mb-3 text-sm font-semibold">Firewall Rules (NSG Ingress)</h3>

            @if ($nsgRules)
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-white/[0.08]">
                                <th class="pb-2 pr-4">Protocol</th>
                                <th class="pb-2 pr-4">Port</th>
                                <th class="pb-2 pr-4">Source</th>
                                <th class="pb-2 pr-4">Description</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nsgRules as $rule)
                                <tr class="border-b border-neutral-100 dark:border-white/[0.04]">
                                    <td class="py-1.5 pr-4 font-mono">{{ $rule['protocol'] === '6' ? 'TCP' : ($rule['protocol'] === '17' ? 'UDP' : $rule['protocol']) }}</td>
                                    <td class="py-1.5 pr-4 font-mono">
                                        @if (isset($rule['port_min']))
                                            {{ $rule['port_min'] }}{{ isset($rule['port_max']) && $rule['port_max'] !== $rule['port_min'] ? '-'.$rule['port_max'] : '' }}
                                        @else
                                            all
                                        @endif
                                    </td>
                                    <td class="py-1.5 pr-4 font-mono">{{ $rule['source'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-4 text-neutral-500">{{ $rule['description'] ?: '—' }}</td>
                                    <td class="py-1.5">
                                        <button wire:click="removeNsgRule('{{ $rule['id'] }}')"
                                            class="text-xs text-red-500 hover:text-red-700">Remove</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mb-3 text-xs text-neutral-500">No ingress rules.</p>
            @endif

            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-neutral-100 pt-3 dark:border-white/[0.04]">
                <select wire:model="newRule.protocol"
                    class="rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900">
                    <option>TCP</option>
                    <option>UDP</option>
                    <option>ICMP</option>
                    <option>ALL</option>
                </select>
                <input wire:model="newRule.port_min" type="number" min="1" max="65535" placeholder="Port min"
                    class="w-20 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                <input wire:model="newRule.port_max" type="number" min="1" max="65535" placeholder="Port max"
                    class="w-20 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                <input wire:model="newRule.source" type="text" placeholder="0.0.0.0/0"
                    class="w-32 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                <input wire:model="newRule.description" type="text" placeholder="Description"
                    class="min-w-0 flex-1 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                <x-forms.button wire:click="addNsgRule" class="px-3 py-1 text-xs">Add</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Block Volumes --}}
    @if ($server->oci_instance_id && $server->oci_compartment_id)
        <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h3 class="mb-3 text-sm font-semibold">Block Volumes</h3>

            @if ($confirmingDetach)
                <div class="mb-3 flex items-center gap-3 rounded border border-orange-200 bg-orange-50 px-3 py-2 dark:border-orange-700 dark:bg-orange-900/20">
                    <span class="text-sm text-orange-700 dark:text-orange-300">Detach this volume? The instance will lose access immediately.</span>
                    <x-forms.button wire:click="detachVolume" class="button-danger px-3 py-1 text-xs">Confirm Detach</x-forms.button>
                    <x-forms.button wire:click="cancelConfirm" class="px-3 py-1 text-xs">Cancel</x-forms.button>
                </div>
            @endif

            @if ($volumeAttachments)
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-white/[0.08]">
                                <th class="pb-2 pr-4">Volume OCID</th>
                                <th class="pb-2 pr-4">Type</th>
                                <th class="pb-2 pr-4">State</th>
                                <th class="pb-2 pr-4">Read-only</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($volumeAttachments as $attachment)
                                <tr class="border-b border-neutral-100 dark:border-white/[0.04]">
                                    <td class="py-1.5 pr-4 font-mono text-neutral-500">{{ Str::limit($attachment['volume_id'], 28) }}</td>
                                    <td class="py-1.5 pr-4">{{ $attachment['attachment_type'] ?? '—' }}</td>
                                    <td class="py-1.5 pr-4">{{ $attachment['lifecycle_state'] }}</td>
                                    <td class="py-1.5 pr-4">{{ $attachment['is_read_only'] ? 'Yes' : 'No' }}</td>
                                    <td class="py-1.5">
                                        <button wire:click="confirmDetachVolume('{{ $attachment['id'] }}')"
                                            class="text-xs text-red-500 hover:text-red-700">Detach</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mb-3 text-xs text-neutral-500">No block volumes attached.</p>
            @endif

            <div class="mt-3 flex items-end gap-2 border-t border-neutral-100 pt-3 dark:border-white/[0.04]">
                <input wire:model="attachVolumeOcid" type="text" placeholder="ocid1.volume.oc1.…"
                    class="min-w-0 flex-1 rounded border border-neutral-200 bg-white px-2 py-1 text-xs font-mono dark:border-white/[0.08] dark:bg-neutral-900" />
                <x-forms.button wire:click="attachVolume" class="px-3 py-1 text-xs">Attach</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Load Balancers --}}
    @if ($server->oci_compartment_id && $loadBalancers)
        <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h3 class="mb-3 text-sm font-semibold">Load Balancers</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-white/[0.08]">
                            <th class="pb-2 pr-4">Name</th>
                            <th class="pb-2 pr-4">Shape</th>
                            <th class="pb-2 pr-4">IPs</th>
                            <th class="pb-2 pr-4">Backend Sets</th>
                            <th class="pb-2">State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($loadBalancers as $lb)
                            <tr class="border-b border-neutral-100 dark:border-white/[0.04]">
                                <td class="py-1.5 pr-4 font-medium">{{ $lb['display_name'] }}</td>
                                <td class="py-1.5 pr-4 font-mono text-neutral-500">{{ $lb['shape_name'] }}</td>
                                <td class="py-1.5 pr-4 font-mono">{{ implode(', ', $lb['ip_addresses']) ?: '—' }}</td>
                                <td class="py-1.5 pr-4 text-neutral-500">{{ implode(', ', $lb['backend_sets']) ?: '—' }}</td>
                                <td class="py-1.5">{{ $lb['lifecycle_state'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Autonomous Databases --}}
    @if ($server->oci_compartment_id && $autonomousDatabases)
        <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h3 class="mb-3 text-sm font-semibold">Autonomous Databases</h3>
            <div class="flex flex-col gap-3">
                @foreach ($autonomousDatabases as $adb)
                    <div class="rounded border border-neutral-100 p-3 dark:border-white/[0.04]">
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-sm font-medium">{{ $adb['display_name'] }}</span>
                            <span class="text-xs text-neutral-500">{{ $adb['lifecycle_state'] }}</span>
                        </div>
                        <div class="mb-2 flex gap-4 text-xs text-neutral-500">
                            <span>DB: {{ $adb['db_name'] }}</span>
                            <span>Workload: {{ $adb['db_workload'] }}</span>
                            <span>{{ $adb['cpu_core_count'] }} OCPUs</span>
                            <span>{{ $adb['data_storage_size_in_tbs'] }} TB</span>
                        </div>
                        @if ($adb['connection_strings'])
                            <details class="text-xs">
                                <summary class="cursor-pointer text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300">Connection Strings</summary>
                                <div class="mt-2 flex flex-col gap-1">
                                    @foreach ($adb['connection_strings'] as $profile => $cs)
                                        <div class="flex gap-2">
                                            <span class="w-20 shrink-0 font-mono text-neutral-400">{{ $profile }}</span>
                                            <span class="break-all font-mono text-neutral-600 dark:text-neutral-400">{{ $cs }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</div>
        </div>
    </div>
</div>
