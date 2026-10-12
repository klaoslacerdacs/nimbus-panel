<div>
    <x-slot:title>OCI Connections | Coolify</x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="OCI Connections"
            description="API key credentials used to provision and manage Oracle Cloud Infrastructure resources." flush>
            <x-slot:actions>
                <x-modal-input title="Add OCI Connection" isLarge>
                    <x-slot:content>
                        <x-forms.button class="button-highlighted">Add connection</x-forms.button>
                    </x-slot:content>
                    <livewire:security.oci-connection-form />
                </x-modal-input>
            </x-slot:actions>

            @if (empty($connections) || $connections->isEmpty())
                <x-empty title="No OCI connections yet"
                    description="Add an OCI connection to start provisioning servers on Oracle Cloud."
                    icon-name="cloud" />
            @else
                <div class="flex flex-col">
                    @foreach ($connections as $connection)
                        <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.08]">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[13px] font-semibold">{{ $connection->name }}</span>
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $connection->authentication_method->value }} · {{ $connection->region }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('security.oci-connections.edit', $connection) }}"
                                    class="button button-secondary text-sm" wire:navigate>
                                    Edit
                                </a>
                                <x-modal-confirmation title="Delete OCI connection?" isErrorButton buttonTitle="Delete"
                                    submitAction="deleteConnection({{ $connection->id }})" :actions="[
                                        'The connection and its stored API key will be permanently deleted.',
                                        'Stack records using it are removed from Nimbus Panel; OCI resources are NOT destroyed.',
                                    ]"
                                    confirmationText="{{ $connection->name }}"
                                    confirmationLabel="Enter the connection name to confirm"
                                    shortConfirmationLabel="Connection name"
                                    :confirmWithPassword="false" step2ButtonText="Delete connection" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </x-security.settings-layout>
</div>
