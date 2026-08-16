<div class="flex flex-col gap-6" wire:poll.30000ms="loadStatus">

    {{-- Provisioning Progress --}}
    @if ($isProvisioning || in_array($stackStatus, ['failed']))
        @php
            $steps = [
                'pending'     => ['label' => 'Queued',    'idx' => 0],
                'planning'    => ['label' => 'Planning',  'idx' => 1],
                'applying'    => ['label' => 'Applying',  'idx' => 2],
                'provisioned' => ['label' => 'Done',      'idx' => 3],
                'failed'      => ['label' => 'Failed',    'idx' => 3],
            ];
            $currentIdx = $steps[$stackStatus]['idx'] ?? 0;
            $isFailed = $stackStatus === 'failed';
        @endphp
        <div wire:poll.5000ms="loadProvisioningStatus"
            class="rounded-lg border {{ $isFailed ? 'border-red-300 dark:border-red-700' : 'border-blue-200 dark:border-blue-700' }} p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">
                    {{ $isFailed ? 'Provisioning Failed' : 'Provisioning in Progress' }}
                </h3>
                @if (! $isFailed)
                    <svg class="h-4 w-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                @endif
            </div>

            <div class="mb-4 flex items-center gap-1">
                @foreach (['Queued', 'Planning', 'Applying', $isFailed ? 'Failed' : 'Done'] as $stepIdx => $stepLabel)
                    @php
                        $done = $stepIdx < $currentIdx;
                        $active = $stepIdx === $currentIdx;
                    @endphp
                    <div class="flex items-center">
                        <div class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium
                            {{ $done ? 'bg-green-500 text-white' : ($active ? ($isFailed ? 'bg-red-500 text-white' : 'bg-blue-500 text-white') : 'bg-neutral-200 text-neutral-500 dark:bg-neutral-700') }}">
                            @if ($done)
                                ✓
                            @elseif ($active && $isFailed)
                                ✕
                            @else
                                {{ $stepIdx + 1 }}
                            @endif
                        </div>
                        <span class="ml-1 text-xs {{ $active ? 'font-semibold' : 'text-neutral-500' }}">{{ $stepLabel }}</span>
                        @if ($stepIdx < 3)
                            <div class="mx-2 h-px w-6 {{ $done ? 'bg-green-400' : 'bg-neutral-200 dark:bg-neutral-700' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($stackMessage)
                <p class="text-xs {{ $isFailed ? 'text-red-600 dark:text-red-400' : 'text-neutral-500' }}">{{ $stackMessage }}</p>
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

</div>
