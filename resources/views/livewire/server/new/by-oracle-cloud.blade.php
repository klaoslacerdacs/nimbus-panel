<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <div class="flex flex-col gap-6">
            <div>
                <label for="selected_connection_id" class="mb-1.5 block text-sm font-medium">Oracle Cloud connection</label>
                <select id="selected_connection_id" wire:model="selected_connection_id" class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                    <option value="">Select a connection…</option>
                    @foreach ($connections as $conn)
                        <option value="{{ $conn->id }}">{{ $conn->name }} · {{ $conn->region }}</option>
                    @endforeach
                </select>
            </div>
            <x-forms.button wire:click="nextStep" :disabled="!$selected_connection_id" class="button-highlighted">
                Continue
            </x-forms.button>
        </div>
    @elseif ($current_step === 2)
        @php
            $privateKeyOptions = $private_keys->map(fn ($key) => [
                'value' => $key->id,
                'label' => $key->name,
            ])->values()->all();
        @endphp

        <form wire:submit="createServer" class="flex flex-col gap-6">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <x-forms.input id="server_name" label="Server name" required />
                </div>
                <x-forms.input id="region" label="Region" wire:model.blur="region" required />
                <x-forms.input id="compartment_ocid" label="Compartment OCID" wire:model.blur="compartment_ocid" required />
                <div>
                    <label for="availability_domain" class="mb-1.5 block text-sm font-medium">Availability Domain <span class="text-red-500">*</span></label>
                    <select id="availability_domain" wire:model="availability_domain" class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                        <option value="">Select an availability domain…</option>
                        @foreach ($availability_domains as $ad)
                            <option value="{{ $ad['name'] }}">{{ $ad['name'] }}</option>
                        @endforeach
                    </select>
                    @error('availability_domain') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="subnet_ocid" class="mb-1.5 block text-sm font-medium">Subnet <span class="text-red-500">*</span></label>
                    <select id="subnet_ocid" wire:model="subnet_ocid" class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-white/[0.08] dark:bg-neutral-900">
                        <option value="">Select a subnet…</option>
                        @foreach ($subnets as $subnet)
                            <option value="{{ $subnet['id'] }}">{{ $subnet['display_name'] }} ({{ $subnet['cidr_block'] }})</option>
                        @endforeach
                    </select>
                    @error('subnet_ocid') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <x-forms.input id="shape" label="Shape" required />
                <x-forms.input id="image_id" label="Image ID" required />
                @if ($private_keys->isEmpty())
                    <div class="lg:col-span-2">
                        <p class="text-sm text-warning">No private keys found. Create one first.</p>
                    </div>
                @else
                    <x-forms.listbox id="private_key_id" label="Private key" required
                        :options="$privateKeyOptions" />
                @endif
                <x-forms.input id="ssh_username" label="SSH Username" required />
            </div>
            <div class="flex gap-2">
                <x-forms.button type="button" wire:click="previousStep">Back</x-forms.button>
                <x-forms.button type="submit" class="button-highlighted" :disabled="$private_keys->isEmpty()" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed">
                    Create server
                </x-forms.button>
            </div>
        </form>
    @endif
</div>
