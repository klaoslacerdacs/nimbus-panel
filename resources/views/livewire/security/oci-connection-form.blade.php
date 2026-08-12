<div class="w-full">
    <form class="flex w-full flex-col gap-4" wire:submit="addConnection">
        <x-forms.input required id="name" label="Connection name" placeholder="Production OCI" />

        <x-forms.listbox required id="authentication_method" label="Authentication method"
            :options="[
                ['value' => 'api_key', 'label' => 'API Key'],
                ['value' => 'instance_principal', 'label' => 'Instance Principal'],
            ]" />

        <x-forms.input required id="region" label="Region" placeholder="us-ashburn-1" />

        @if ($authentication_method === 'api_key')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="tenancy_ocid" label="Tenancy OCID"
                    placeholder="ocid1.tenancy.oc1.." />
                <x-forms.input required id="user_ocid" label="User OCID"
                    placeholder="ocid1.user.oc1.." />
                <x-forms.input required id="fingerprint" label="Fingerprint"
                    placeholder="aa:bb:cc:.." />
                <x-forms.input required type="password" id="private_key" label="Private key (PEM)"
                    placeholder="-----BEGIN RSA PRIVATE KEY-----" />
                <x-forms.input type="password" id="passphrase" label="Passphrase (optional)"
                    placeholder="Leave empty if none" />
            </div>
        @endif

        <x-forms.input id="compartment_ocid" label="Default compartment OCID (optional)"
            placeholder="ocid1.compartment.oc1.." />

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit" class="button-highlighted" wire:target="addConnection">
                Validate and add
            </x-forms.button>
        </div>
    </form>
</div>
