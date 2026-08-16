<div class="flex flex-col gap-2">
    @if ($connections->isNotEmpty())
        <div class="flex items-center justify-between">
            <h3>OCI Connections</h3>
            <x-forms.button wire:click="$dispatch('open-modal', 'add-oci-connection')" class="button-highlighted">
                Add connection
            </x-forms.button>
        </div>
        <div class="flex flex-col gap-2">
            @foreach ($connections as $connection)
                <div class="flex items-center justify-between rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ $connection->name }}</span>
                        <span class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ $connection->authentication_method->value }} · {{ $connection->region }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-neutral-400">
                            {{ $connection->tenancy_ocid ? 'API Key' : 'Instance Principal' }}
                        </span>
                        <x-forms.button wire:click="deleteConnection({{ $connection->id }})"
                            wire:confirm="Are you sure? This cannot be undone."
                            class="button-error">
                            Delete
                        </x-forms.button>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex flex-col items-center justify-center gap-2 p-8">
            <p class="text-neutral-500 dark:text-fg-dim">No OCI connections yet.</p>
            <x-forms.button wire:click="$dispatch('open-modal', 'add-oci-connection')" class="button-highlighted">
                Add your first connection
            </x-forms.button>
        </div>
    @endif
</div>
