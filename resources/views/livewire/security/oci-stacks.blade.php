<div>
    <x-slot:title>OCI Stacks | Coolify</x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="OCI Stacks"
            description="Terraform stacks that provision and manage Oracle Cloud compute infrastructure." flush>
            <x-slot:actions>
                @can('create', App\Models\Server::class)
                    <a href="{{ route('server.new.oracle-cloud') }}" {{ wireNavigate() }} class="button button-highlighted">
                        New OCI server
                    </a>
                @endcan
            </x-slot:actions>

            @if (empty($stacks) || $stacks->isEmpty())
                <x-empty title="No OCI stacks yet"
                    description="Stacks are created when you provision a server on Oracle Cloud."
                    icon-name="layers" />
            @else
                <div class="flex flex-col" @if($stacks->contains(fn($s) => $s->isActive())) wire:poll.5000ms="loadStacks" @endif>
                    @foreach ($stacks as $stack)
                        <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.08]">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[13px] font-semibold">{{ $stack->name }}</span>
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $stack->ociConnection->name ?? 'Unknown' }} · {{ $stack->region }}
                                    @if($stack->server?->ip)
                                        · {{ $stack->server->ip }}
                                    @endif
                                </span>
                                @if (in_array($stack->status, ['destroyed', 'destroy_failed', 'failed']) && $stack->last_plan_summary)
                                    <span class="max-w-xl truncate text-xs {{ $stack->status === 'destroyed' ? 'text-neutral-500 dark:text-fg-dim' : 'text-error' }}" title="{{ $stack->last_plan_summary }}">
                                        {{ str($stack->last_plan_summary)->before("\n")->limit(160) }} · {{ $stack->updated_at?->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-3">
                                @php
                                    $badge = match($stack->status) {
                                        'provisioned'    => ['text' => 'Provisioned',   'type' => 'success'],
                                        'planning'       => ['text' => 'Planning…',     'type' => 'warning'],
                                        'applying'       => ['text' => 'Applying…',     'type' => 'warning'],
                                        'destroying'     => ['text' => 'Destroying…',   'type' => 'warning'],
                                        'destroy_failed' => ['text' => 'Destroy failed','type' => 'error'],
                                        'destroyed'      => ['text' => 'Destroyed on OCI', 'type' => 'neutral'],
                                        'failed'         => ['text' => 'Failed',        'type' => 'error'],
                                        default          => ['text' => ucfirst($stack->status), 'type' => 'neutral'],
                                    };
                                @endphp
                                <x-status-badge :label="$badge['text']" :type="$badge['type']" />

                                <a href="{{ route('security.oci-stacks.show', $stack) }}"
                                    class="button button-secondary text-sm" wire:navigate>
                                    Details
                                </a>

                                @if($stackPendingDestroy === $stack->id)
                                    <span class="text-xs text-error">Destroy OCI resources?</span>
                                    <x-forms.button wire:click="destroyStack" class="button-error">Confirm</x-forms.button>
                                    <x-forms.button wire:click="cancelDestroy" class="button-bordered">Cancel</x-forms.button>
                                @else
                                    <x-forms.button wire:click="deleteStack({{ $stack->id }})" class="{{ $stack->status === 'destroyed' ? 'button-bordered' : 'button-error' }}">
                                        {{ match ($stack->status) { 'destroyed' => 'Remove record', 'destroy_failed' => 'Retry destroy', default => 'Delete' } }}
                                    </x-forms.button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </x-security.settings-layout>
</div>
