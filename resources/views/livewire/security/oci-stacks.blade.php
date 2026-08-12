<div class="flex flex-col gap-2">
    @if ($stacks->isNotEmpty())
        <div class="flex items-center justify-between">
            <h3>OCI Stacks</h3>
            <x-forms.button wire:click="$dispatch('open-modal', { id: 'add-oci-stack', component: 'security.oci-stack-form' })" class="button-highlighted">
                Add stack
            </x-forms.button>
        </div>
        <div class="flex flex-col gap-2">
            @foreach ($stacks as $stack)
                <div class="flex items-center justify-between rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ $stack->name }}</span>
                        <span class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ $stack->ociConnection->name ?? 'Unknown' }} · {{ $stack->status }} · {{ $stack->region }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-forms.button wire:click="deleteStack({{ $stack->id }})"
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
            <p class="text-neutral-500 dark:text-fg-dim">No OCI stacks yet.</p>
            <x-forms.button wire:click="$dispatch('open-modal', { id: 'add-oci-stack', component: 'security.oci-stack-form' })" class="button-highlighted">
                Add your first stack
            </x-forms.button>
        </div>
    @endif
</div>
