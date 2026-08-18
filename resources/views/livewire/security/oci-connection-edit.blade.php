<div>
    <x-slot:title>Edit OCI Connection | Coolify</x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Edit OCI Connection">
            <form class="flex w-full flex-col gap-4" wire:submit="updateConnection">
                <x-forms.input required id="name" label="Connection name" placeholder="Production OCI" />

                <x-forms.listbox required live id="authentication_method" label="Authentication method"
                    :options="[
                        ['value' => 'api_key', 'label' => 'API Key'],
                        ['value' => 'instance_principal', 'label' => 'Instance Principal'],
                    ]" />

                <x-forms.select required id="region" label="Region" wire:model="region">
                    @foreach(config('oci.regions') as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                    @endforeach
                </x-forms.select>

                @if ($authentication_method === 'api_key')
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input required id="tenancy_ocid" label="Tenancy OCID"
                            placeholder="ocid1.tenancy.oc1.." />
                        <x-forms.input required id="user_ocid" label="User OCID"
                            placeholder="ocid1.user.oc1.." />
                        <x-forms.input required id="fingerprint" label="Fingerprint"
                            placeholder="aa:bb:cc:.." />
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
                        Leave the private key blank to keep the existing key. Enter a new PEM to rotate it.
                    </div>

                    <x-forms.textarea monospace rows="10" id="private_key" label="Private key (PEM — leave blank to keep)"
                        placeholder="-----BEGIN RSA PRIVATE KEY-----" />
                    <x-forms.input type="password" id="passphrase" label="Passphrase (leave blank to keep)"
                        placeholder="Only needed when rotating the key" />
                @endif

                <x-forms.input id="compartment_ocid" label="Default compartment OCID (optional)"
                    placeholder="ocid1.compartment.oc1.." />

                <div class="flex items-center justify-between border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                    <a href="{{ route('security.oci-connections') }}" class="button button-secondary" wire:navigate>
                        ← Back
                    </a>
                    <x-forms.button type="submit" class="button-highlighted" wire:target="updateConnection">
                        Validate and save
                    </x-forms.button>
                </div>
            </form>
        </x-application.settings-section>
    </x-security.settings-layout>
</div>
