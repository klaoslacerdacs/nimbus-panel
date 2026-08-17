<div class="flex flex-col gap-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="mb-1 flex items-center gap-2">
                <a href="{{ route('security.oci-stacks') }}" class="text-sm text-neutral-500 hover:underline">OCI Stacks</a>
                <span class="text-neutral-300 dark:text-neutral-600">/</span>
                <span class="text-sm font-medium">{{ $stack->name }}</span>
            </div>
            @php
                $badge = match($stack->status) {
                    'provisioned'    => ['text' => 'Provisioned',   'class' => 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20'],
                    'planning'       => ['text' => 'Planning…',     'class' => 'text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/20'],
                    'applying'       => ['text' => 'Applying…',     'class' => 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20'],
                    'destroying'     => ['text' => 'Destroying…',   'class' => 'text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20'],
                    'destroy_failed' => ['text' => 'Destroy failed','class' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'],
                    'failed'         => ['text' => 'Failed',        'class' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'],
                    default          => ['text' => ucfirst($stack->status), 'class' => 'text-neutral-500 bg-neutral-100 dark:bg-neutral-800'],
                };
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge['class'] }}">
                @if ($stack->isActive())
                    <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                @endif
                {{ $badge['text'] }}
            </span>
        </div>

        <div class="flex items-center gap-2">
            @if ($stack->server)
                <a href="{{ route('server.oci', $stack->server->uuid) }}"
                    class="rounded border border-neutral-200 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:bg-neutral-800">
                    View Server
                </a>
            @endif
            @if ($stack->stack_ocid)
                <a href="https://cloud.oracle.com/resourcemanager/stacks/{{ $stack->stack_ocid }}?region={{ $stack->region }}"
                    target="_blank" rel="noopener noreferrer"
                    class="rounded border border-neutral-200 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:bg-neutral-800">
                    OCI Console ↗
                </a>
            @endif
        </div>
    </div>

    @if ($stack->last_plan_summary && in_array($stack->status, ['failed', 'destroy_failed']))
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $stack->last_plan_summary }}
        </div>
    @endif

    {{-- Overview --}}
    <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
        <h3 class="mb-3 text-sm font-semibold">Overview</h3>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div class="flex gap-2">
                <dt class="w-36 flex-shrink-0 text-neutral-500">Connection</dt>
                <dd class="font-mono text-xs">{{ $stack->ociConnection?->name ?? '—' }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-36 flex-shrink-0 text-neutral-500">Region</dt>
                <dd class="font-mono text-xs">{{ $stack->region }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-36 flex-shrink-0 text-neutral-500">Stack OCID</dt>
                <dd class="break-all font-mono text-xs">{{ $stack->stack_ocid ?? '—' }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-36 flex-shrink-0 text-neutral-500">Compartment OCID</dt>
                <dd class="break-all font-mono text-xs">{{ $stack->compartment_ocid ?? '—' }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-36 flex-shrink-0 text-neutral-500">VCN OCID</dt>
                <dd class="break-all font-mono text-xs">{{ $stack->vcn_ocid ?? '—' }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="w-36 flex-shrink-0 text-neutral-500">NSG OCID</dt>
                <dd class="break-all font-mono text-xs">{{ $stack->nsg_ocid ?? '—' }}</dd>
            </div>
            @if ($stack->oci_instance_id)
                <div class="flex gap-2">
                    <dt class="w-36 flex-shrink-0 text-neutral-500">Instance OCID</dt>
                    <dd class="break-all font-mono text-xs">{{ $stack->oci_instance_id }}</dd>
                </div>
            @endif
            @if ($stack->server?->ip)
                <div class="flex gap-2">
                    <dt class="w-36 flex-shrink-0 text-neutral-500">Public IP</dt>
                    <dd class="font-mono text-xs">{{ $stack->server->ip }}</dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- Terraform Variables --}}
    @if ($stack->tf_vars)
        <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h3 class="mb-3 text-sm font-semibold">Terraform Variables</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-white/[0.08]">
                            <th class="pb-2 pr-6 font-medium">Variable</th>
                            <th class="pb-2 font-medium">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stack->tf_vars as $key => $value)
                            <tr class="border-b border-neutral-100 dark:border-white/[0.04]">
                                <td class="py-1.5 pr-6 font-mono text-neutral-600 dark:text-neutral-400">{{ $key }}</td>
                                <td class="py-1.5 font-mono break-all">
                                    @if (in_array($key, ['ssh_authorized_keys']))
                                        <span class="text-neutral-400 italic">hidden</span>
                                    @else
                                        {{ $value ?: '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Firewall Rules --}}
    @if ($stack->firewall_rules)
        <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
            <h3 class="mb-3 text-sm font-semibold">Initial Firewall Rules</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-white/[0.08]">
                            <th class="pb-2 pr-4">Protocol</th>
                            <th class="pb-2 pr-4">Port</th>
                            <th class="pb-2 pr-4">Source</th>
                            <th class="pb-2">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stack->firewall_rules as $rule)
                            <tr class="border-b border-neutral-100 dark:border-white/[0.04]">
                                <td class="py-1.5 pr-4 font-mono">{{ $rule['protocol'] ?? '—' }}</td>
                                <td class="py-1.5 pr-4 font-mono">
                                    {{ $rule['port_min'] ?? 'all' }}{{ isset($rule['port_max']) && $rule['port_max'] != $rule['port_min'] ? '-'.$rule['port_max'] : '' }}
                                </td>
                                <td class="py-1.5 pr-4 font-mono">{{ $rule['source'] ?? '—' }}</td>
                                <td class="py-1.5 text-neutral-500">{{ $rule['description'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
