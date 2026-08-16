<div class="flex flex-col gap-2" @if($stacks->contains(fn($s) => $s->isActive())) wire:poll.5000ms="loadStacks" @endif>
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
                    <div class="flex flex-col gap-1">
                        <span class="font-medium">{{ $stack->name }}</span>
                        <span class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ $stack->ociConnection->name ?? 'Unknown' }} · {{ $stack->region }}
                            @if($stack->server?->ip)
                                · {{ $stack->server->ip }}
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        @php
                            $badge = match($stack->status) {
                                'provisioned'    => ['text' => 'Provisioned',  'class' => 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20'],
                                'planning'       => ['text' => 'Planning…',    'class' => 'text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-900/20'],
                                'applying'       => ['text' => 'Applying…',    'class' => 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20'],
                                'destroying'     => ['text' => 'Destroying…',  'class' => 'text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20'],
                                'destroy_failed' => ['text' => 'Destroy failed','class' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'],
                                'failed'         => ['text' => 'Failed',       'class' => 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'],
                                default          => ['text' => ucfirst($stack->status), 'class' => 'text-neutral-500 dark:text-fg-dim bg-neutral-100 dark:bg-neutral-800'],
                            };
                        @endphp
                        <span class="flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge['class'] }}">
                            @if(in_array($stack->status, ['planning', 'applying', 'destroying']))
                                <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                </svg>
                            @endif
                            {{ $badge['text'] }}
                        </span>

                        @if($stackPendingDestroy === $stack->id)
                            <span class="text-xs text-red-600 dark:text-red-400">Destroy OCI resources?</span>
                            <x-forms.button wire:click="destroyStack" class="button-error">Confirm</x-forms.button>
                            <x-forms.button wire:click="cancelDestroy" class="button-bordered">Cancel</x-forms.button>
                        @else
                            <x-forms.button wire:click="deleteStack({{ $stack->id }})" class="button-error">
                                Delete
                            </x-forms.button>
                        @endif
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
