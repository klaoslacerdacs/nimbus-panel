<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        {{-- Step 1: Connection + region + compartment --}}
        <div class="flex flex-col gap-6">
            <div>
                <h3 class="text-base font-semibold">Import an existing Oracle Cloud VM</h3>
                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">Connect a VM you already created in Oracle Cloud. Nothing is provisioned — Nimbus only manages the existing instance.</p>
            </div>

            <div>
                <label for="oci_connection_id" class="mb-1.5 block text-sm font-medium">Oracle Cloud connection <span class="text-red-500">*</span></label>
                @if ($connections->isEmpty())
                    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                        <p class="font-medium">No OCI connections configured yet.</p>
                        <p class="mt-1 text-neutral-500 dark:text-fg-dim">
                            Go to <a href="{{ route('security.oci-connections') }}" class="underline" wire:navigate>Security → OCI Connections</a> to add your Oracle Cloud API key, then come back here.
                        </p>
                    </div>
                @else
                    <select id="oci_connection_id" wire:model.live="oci_connection_id"
                        class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                        <option value="">Select a connection…</option>
                        @foreach ($connections as $conn)
                            <option value="{{ $conn->id }}">{{ $conn->name }} · {{ $conn->region }}</option>
                        @endforeach
                    </select>
                    @error('oci_connection_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium" for="region">Region <span class="text-red-500">*</span></label>
                @if ($regions)
                    <select id="region" wire:model.live="region"
                        class="w-full rounded border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                        @foreach ($regions as $r)
                            <option value="{{ $r }}">{{ $r }}</option>
                        @endforeach
                    </select>
                @else
                    <x-forms.input id="region" wire:model.blur="region" required placeholder="e.g. sa-saopaulo-1" />
                @endif
                @error('region') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="compartment_id" class="mb-1.5 block text-sm font-medium">Compartment <span class="text-red-500">*</span></label>
                @if ($compartments)
                    <select id="compartment_id" wire:model.live="compartment_id"
                        class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                        <option value="">Select a compartment…</option>
                        @foreach ($compartments as $comp)
                            <option value="{{ $comp['id'] }}">{{ $comp['name'] }}</option>
                        @endforeach
                    </select>
                @else
                    <x-forms.input id="compartment_id" wire:model.live="compartment_id" placeholder="ocid1.compartment.oc1…" />
                @endif
                @error('compartment_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <x-forms.button wire:click="nextStep" class="button-highlighted" :disabled="$connections->isEmpty()">
                Continue
            </x-forms.button>
        </div>

    @elseif ($current_step === 2)
        {{-- Step 2: Instance selection --}}
        <div class="flex flex-col gap-6">
            <div>
                <h3 class="text-base font-semibold">Select an instance</h3>
                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">Choose the VM to import. Its primary IP is fetched automatically.</p>
            </div>

            @if (empty($instances))
                <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                    No importable instances found in this compartment / region.
                </div>
            @else
                <div class="flex flex-col divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.08] dark:border-white/[0.08]">
                    @foreach ($instances as $instance)
                        @php
                            $state = $instance['lifecycle_state'] ?? '';
                            $badge = match ($state) {
                                'RUNNING' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                'STOPPED' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                                default => 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
                            };
                            $selected = $oci_instance_id === $instance['id'];
                        @endphp
                        <button type="button" wire:click="selectInstance('{{ $instance['id'] }}')"
                            class="flex items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-neutral-50 dark:hover:bg-white/[0.03] {{ $selected ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $instance['display_name'] ?? $instance['id'] }}</p>
                                <p class="truncate text-xs text-neutral-500">{{ $instance['shape'] ?? '—' }} · {{ $instance['region'] ?? $region }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">{{ $state ?: 'UNKNOWN' }}</span>
                        </button>
                    @endforeach
                </div>
                @error('oci_instance_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            @endif

            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="previousStep">Back</x-forms.button>
                <x-forms.button wire:click="nextStep" class="button-highlighted" :disabled="!$oci_instance_id">Continue</x-forms.button>
            </div>
        </div>

    @elseif ($current_step === 3)
        {{-- Step 3: Server config --}}
        @php
            $privateKeyOptions = $private_keys->map(fn ($key) => [
                'value' => $key->id,
                'label' => $key->name,
            ])->values()->all();
        @endphp
        <div class="flex flex-col gap-6">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <x-forms.input id="server_name" wire:model="server_name" label="Server name" required />
                </div>
                <div class="lg:col-span-2">
                    <x-forms.input id="ip" wire:model="ip" label="IP address" required />
                    <p class="mt-1 text-xs text-neutral-500">This key's public part must already be in <code>~/.ssh/authorized_keys</code> on the target VM.</p>
                </div>
                <x-forms.input id="ssh_username" wire:model="ssh_username" label="SSH username" required />
                @if ($private_keys->isEmpty())
                    <div>
                        <p class="text-sm text-warning">No private keys found. Create one first.</p>
                    </div>
                @else
                    <x-forms.listbox id="private_key_id" label="Private key" required :options="$privateKeyOptions" />
                @endif
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
                <x-forms.button wire:click="createServer" class="button-highlighted" :disabled="$private_keys->isEmpty()"
                    wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed">
                    Import server
                </x-forms.button>
            </div>
        </div>
    @endif
</div>
