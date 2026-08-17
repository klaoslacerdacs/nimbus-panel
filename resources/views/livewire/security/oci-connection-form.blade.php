<div class="w-full">
    <form class="flex w-full flex-col gap-4" wire:submit="addConnection">
        <x-forms.input required id="name" label="Connection name" placeholder="Production OCI" />

        <x-forms.listbox required live id="authentication_method" label="Authentication method"
            :options="[
                ['value' => 'api_key', 'label' => 'API Key'],
                ['value' => 'instance_principal', 'label' => 'Instance Principal'],
            ]" />

        <x-forms.input required id="region" label="Region" placeholder="us-ashburn-1" />

        @if ($authentication_method === 'api_key')
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-800/40 dark:bg-blue-900/20 dark:text-blue-300">
                In the OCI Console: open the <strong>Profile menu → My profile → API keys → Add API key</strong>. Download the private key and copy the config snippet — it contains your tenancy OCID, user OCID, fingerprint, and region.
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input required id="tenancy_ocid" label="Tenancy OCID"
                    placeholder="ocid1.tenancy.oc1.."
                    helper="Found in OCI Console → Profile menu → Tenancy: &lt;name&gt; → Tenancy OCID" />
                <x-forms.input required id="user_ocid" label="User OCID"
                    placeholder="ocid1.user.oc1.."
                    helper="Found in OCI Console → Profile menu → My profile → OCID" />
                <x-forms.input required id="fingerprint" label="Fingerprint"
                    placeholder="aa:bb:cc:.."
                    helper="Shown in the config snippet after adding an API key" />
                <div class="lg:col-span-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
                    The private key is cleared on every server response for security. If validation fails, you must re-enter the PEM.
                </div>
                <div class="lg:col-span-2">
                    <x-forms.textarea required monospace rows="10" id="private_key" label="Private key (PEM)"
                        placeholder="-----BEGIN RSA PRIVATE KEY-----" />
                </div>
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
