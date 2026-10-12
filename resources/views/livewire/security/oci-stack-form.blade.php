<div class="w-full">
    <form class="flex w-full flex-col gap-4" wire:submit="createStack">
        <x-forms.input required id="name" label="Stack name" placeholder="Production Stack" wire:model="name" />

        <x-forms.listbox required id="oci_connection_id" label="OCI Connection" placeholder="Select a connection"
            :options="$connections->map(fn ($c) => ['value' => $c->id, 'label' => $c->name.' ('.$c->region.')'])->all()" />

        <x-forms.listbox required id="config_source" label="Config Source" wire:model="config_source"
            :options="[
                ['value' => 'template_zip', 'label' => 'Template ZIP'],
                ['value' => 'git_repo', 'label' => 'Git Repository'],
                ['value' => 'compartment_discovery', 'label' => 'Compartment Discovery'],
            ]" />

        @if(in_array($config_source, ['template_zip', 'git_repo']))
            <x-forms.input required id="config_url" label="Config URL" placeholder="https://example.com/config.zip" wire:model="config_url" />
        @endif

        <x-forms.input required id="compartment_ocid" label="Compartment OCID" placeholder="ocid1.compartment.oc1.." wire:model="compartment_ocid" />

        <x-forms.input required id="region" label="Region" placeholder="us-ashburn-1" wire:model="region" />

        <p class="text-xs text-neutral-500 dark:text-fg-dim">
            Terraform variables (shape, image, SSH key, etc.) must be configured before provisioning.
            Use the <a href="{{ route('server.new.oracle-cloud') }}" class="underline" wire:navigate>New server wizard</a> to provision a full stack automatically.
        </p>

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit" class="button-highlighted" wire:target="createStack">
                Create Stack
            </x-forms.button>
        </div>
    </form>
</div>
