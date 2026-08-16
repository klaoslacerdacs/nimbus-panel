<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        {{-- Step 1: Connection --}}
        <div class="flex flex-col gap-6">
            <div>
                <label for="selected_connection_id" class="mb-1.5 block text-sm font-medium">Oracle Cloud connection</label>
                <select id="selected_connection_id" wire:model="selected_connection_id"
                    class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                    <option value="">Select a connection…</option>
                    @foreach ($connections as $conn)
                        <option value="{{ $conn->id }}">{{ $conn->name }} · {{ $conn->region }}</option>
                    @endforeach
                </select>
                @error('selected_connection_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <x-forms.button wire:click="nextStep" :disabled="!$selected_connection_id" class="button-highlighted">
                Continue
            </x-forms.button>
        </div>

    @elseif ($current_step === 2)
        {{-- Step 2: Region + Compartment --}}
        <div class="flex flex-col gap-6">
            <x-forms.input id="region" label="Region" wire:model.blur="region" required placeholder="e.g. sa-saopaulo-1" />

            <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm font-medium">Compartment</span>
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="create_compartment" class="rounded">
                        Create new
                    </label>
                </div>

                @if ($create_compartment)
                    <div class="flex flex-col gap-3">
                        <x-forms.input id="compartment_name" label="Compartment name" wire:model="compartment_name" required
                            placeholder="e.g. nimbus-production" />
                        <x-forms.input id="parent_compartment_ocid" label="Parent compartment OCID (optional)"
                            wire:model="parent_compartment_ocid" placeholder="Leave blank to use tenancy root" />
                    </div>
                @else
                    <div>
                        <label for="compartment_ocid" class="mb-1.5 block text-sm font-medium">Compartment <span class="text-red-500">*</span></label>
                        @if ($compartments)
                            <select id="compartment_ocid" wire:model.live="compartment_ocid"
                                class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                                <option value="">Select a compartment…</option>
                                @foreach ($compartments as $comp)
                                    <option value="{{ $comp['id'] }}">{{ $comp['name'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <x-forms.input id="compartment_ocid" wire:model.live="compartment_ocid"
                                placeholder="ocid1.compartment.oc1…" />
                        @endif
                        @error('compartment_ocid') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="previousStep">Back</x-forms.button>
                <x-forms.button wire:click="nextStep" class="button-highlighted">Continue</x-forms.button>
            </div>
        </div>

    @elseif ($current_step === 3)
        {{-- Step 3: Network --}}
        <div class="flex flex-col gap-6">
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm font-medium">Network</span>
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="create_network" class="rounded">
                        Create new
                    </label>
                </div>

                @if ($create_network)
                    <div class="flex flex-col gap-3">
                        <x-forms.input id="vcn_display_name" label="VCN name" wire:model="vcn_display_name" required
                            placeholder="e.g. nimbus-production-vcn" />
                        <x-forms.input id="vcn_dns_label" label="DNS label (optional)" wire:model="vcn_dns_label"
                            placeholder="Auto-derived from VCN name" />
                        <div>
                            <label class="mb-1.5 block text-sm font-medium">VCN CIDR block</label>
                            <div class="mb-2 flex flex-wrap gap-2">
                                @foreach (['10.0.0.0/16', '172.16.0.0/16', '192.168.0.0/16'] as $preset)
                                    <button type="button"
                                        wire:click="$set('vcn_cidr', '{{ $preset }}')"
                                        class="rounded border px-3 py-1 text-xs transition {{ $vcn_cidr === $preset ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'border-neutral-200 dark:border-white/[0.08]' }}">
                                        {{ $preset }}
                                    </button>
                                @endforeach
                            </div>
                            <x-forms.input id="vcn_cidr" wire:model="vcn_cidr" placeholder="Custom CIDR" />
                            @error('vcn_cidr') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-forms.input id="subnet_cidr" label="Subnet CIDR (optional)" wire:model="subnet_cidr"
                                placeholder="Auto: first /24 of VCN CIDR" />
                            <p class="mt-1 text-xs text-neutral-500">
                                @if ($vcn_cidr && str_contains($vcn_cidr, '/'))
                                    @php
                                        try {
                                            [$base] = explode('/', $vcn_cidr);
                                            $parts = explode('.', $base);
                                            $auto = $parts[0].'.'.$parts[1].'.0.0/24';
                                        } catch (\Throwable) {
                                            $auto = null;
                                        }
                                    @endphp
                                    @if (! $subnet_cidr && isset($auto)) Auto: {{ $auto }} @endif
                                @endif
                            </p>
                        </div>
                    </div>
                @else
                    <div class="flex flex-col gap-3">
                        <div>
                            <label for="vcn_ocid" class="mb-1.5 block text-sm font-medium">VCN <span class="text-red-500">*</span></label>
                            @if ($vcns)
                                <select id="vcn_ocid" wire:model.live="vcn_ocid"
                                    class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                                    <option value="">Select a VCN…</option>
                                    @foreach ($vcns as $vcn)
                                        <option value="{{ $vcn['id'] }}">{{ $vcn['display_name'] }} ({{ implode(', ', $vcn['cidr_blocks'] ?? []) }})</option>
                                    @endforeach
                                </select>
                            @else
                                <x-forms.input id="vcn_ocid" wire:model.live="vcn_ocid" placeholder="ocid1.vcn.oc1…" />
                            @endif
                            @error('vcn_ocid') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="subnet_ocid" class="mb-1.5 block text-sm font-medium">Subnet <span class="text-red-500">*</span></label>
                            @if ($subnets)
                                <select id="subnet_ocid" wire:model="subnet_ocid"
                                    class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                                    <option value="">Select a subnet…</option>
                                    @foreach ($subnets as $subnet)
                                        <option value="{{ $subnet['id'] }}">{{ $subnet['display_name'] }} ({{ $subnet['cidr_block'] }})</option>
                                    @endforeach
                                </select>
                            @else
                                <x-forms.input id="subnet_ocid" wire:model="subnet_ocid" placeholder="ocid1.subnet.oc1…" />
                            @endif
                            @error('subnet_ocid') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif
            </div>

            {{-- Firewall rules (NSG ingress) --}}
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm font-medium">Firewall rules (ingress)</span>
                    <button type="button" wire:click="addFirewallRule"
                        class="text-xs text-blue-600 hover:underline dark:text-blue-400">+ Add rule</button>
                </div>
                <div class="flex flex-col gap-2">
                    @foreach ($firewall_rules as $i => $rule)
                        <div class="flex items-center gap-2">
                            <select wire:model="firewall_rules.{{ $i }}.protocol"
                                class="w-20 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900">
                                <option>TCP</option>
                                <option>UDP</option>
                                <option>ICMP</option>
                                <option>ALL</option>
                            </select>
                            <input wire:model="firewall_rules.{{ $i }}.port_min" type="number" min="1" max="65535"
                                placeholder="Port min" class="w-24 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                            <input wire:model="firewall_rules.{{ $i }}.port_max" type="number" min="1" max="65535"
                                placeholder="Port max" class="w-24 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                            <input wire:model="firewall_rules.{{ $i }}.source" type="text"
                                placeholder="0.0.0.0/0" class="min-w-0 flex-1 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                            <input wire:model="firewall_rules.{{ $i }}.description" type="text"
                                placeholder="Description" class="min-w-0 flex-1 rounded border border-neutral-200 bg-white px-2 py-1 text-xs dark:border-white/[0.08] dark:bg-neutral-900" />
                            <button type="button" wire:click="removeFirewallRule({{ $i }})"
                                class="text-xs text-red-500 hover:text-red-700">×</button>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-neutral-500">Rules applied to the NSG attached to this instance.</p>
            </div>

            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="previousStep">Back</x-forms.button>
                <x-forms.button wire:click="nextStep" class="button-highlighted">Continue</x-forms.button>
            </div>
        </div>

    @elseif ($current_step === 4)
        {{-- Step 4: Compute --}}
        @php
            $privateKeyOptions = $private_keys->map(fn ($key) => [
                'value' => $key->id,
                'label' => $key->name,
            ])->values()->all();
        @endphp
        <div class="flex flex-col gap-6">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <x-forms.input id="server_name" label="Server / instance name" required />
                </div>
                <div>
                    <label for="availability_domain" class="mb-1.5 block text-sm font-medium">Availability domain <span class="text-red-500">*</span></label>
                    <select id="availability_domain" wire:model="availability_domain"
                        class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                        <option value="">Select…</option>
                        @foreach ($availability_domains as $ad)
                            <option value="{{ $ad['name'] }}">{{ $ad['name'] }}</option>
                        @endforeach
                    </select>
                    @error('availability_domain') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <x-forms.input id="shape" label="Shape" required />
                <div class="lg:col-span-2">
                    <x-forms.input id="image_id" label="Image OCID" required />
                </div>
                @if (str_contains($shape, 'Flex'))
                    <x-forms.input id="ocpus" label="OCPUs" type="number" min="1" />
                    <x-forms.input id="memory_in_gbs" label="Memory (GB)" type="number" min="1" />
                @endif
                <x-forms.input id="boot_volume_size_in_gbs" label="Boot volume (GB)" type="number" min="50" />
                @if ($private_keys->isEmpty())
                    <div class="lg:col-span-2">
                        <p class="text-sm text-warning">No private keys found. Create one first.</p>
                    </div>
                @else
                    <x-forms.listbox id="private_key_id" label="Private key" required :options="$privateKeyOptions" />
                @endif
                <x-forms.input id="ssh_username" label="SSH username" required />
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium">Metrics source</label>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" wire:model="oci_metrics_source" value="oci_monitoring" class="text-blue-600">
                            <span>OCI Monitoring <span class="block text-xs text-neutral-500">CPU always; memory/network require OCI agent on VM</span></span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" wire:model="oci_metrics_source" value="sentinel" class="text-blue-600">
                            <span>SSH / Sentinel <span class="block text-xs text-neutral-500">Coolify standard — requires SSH + Sentinel container</span></span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="previousStep">Back</x-forms.button>
                <x-forms.button wire:click="nextStep" class="button-highlighted" :disabled="$private_keys->isEmpty()">
                    Review
                </x-forms.button>
            </div>
        </div>

    @elseif ($current_step === 5)
        {{-- Step 5: Review + Submit --}}
        <div class="flex flex-col gap-6">
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                <h3 class="mb-3 text-sm font-semibold">Review</h3>
                <dl class="grid gap-y-2 text-sm sm:grid-cols-2">
                    <dt class="text-neutral-500">Server name</dt>
                    <dd class="font-mono">{{ $server_name }}</dd>
                    <dt class="text-neutral-500">Region</dt>
                    <dd>{{ $region }}</dd>
                    <dt class="text-neutral-500">Compartment</dt>
                    <dd>{{ $create_compartment ? 'Create: '.$compartment_name : $compartment_ocid }}</dd>
                    <dt class="text-neutral-500">Network</dt>
                    <dd>{{ $create_network ? 'Create VCN ('.$vcn_cidr.')' : 'Existing VCN' }}</dd>
                    <dt class="text-neutral-500">Availability domain</dt>
                    <dd>{{ $availability_domain }}</dd>
                    <dt class="text-neutral-500">Shape</dt>
                    <dd>{{ $shape }}</dd>
                    <dt class="text-neutral-500">Firewall rules</dt>
                    <dd>{{ count($firewall_rules) }} ingress {{ Str::plural('rule', count($firewall_rules)) }}</dd>
                    <dt class="text-neutral-500">Metrics</dt>
                    <dd>{{ $oci_metrics_source === 'oci_monitoring' ? 'OCI Monitoring' : 'SSH / Sentinel' }}</dd>
                    @if (str_contains($shape, 'Flex'))
                        <dt class="text-neutral-500">OCPUs / Memory</dt>
                        <dd>{{ $ocpus }} / {{ $memory_in_gbs }} GB</dd>
                    @endif
                </dl>
            </div>
            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="previousStep">Back</x-forms.button>
                <x-forms.button wire:click="createServer" class="button-highlighted"
                    wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed">
                    Create server
                </x-forms.button>
            </div>
        </div>
    @endif
</div>
