<div @if ($jobs->contains(fn ($job) => $job->isRunning())) wire:poll.5000ms @endif>
    <x-slot:title>Terraform Jobs | Coolify</x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Terraform Jobs"
            description="Every plan, apply and destroy run by OCI Resource Manager, with its status and Terraform log." flush>
            <div class="grid gap-3 border-b border-neutral-200 px-4 py-3 sm:grid-cols-2 dark:border-white/[0.08]">
                <x-forms.listbox id="operation" label="Operation" live placeholder="All operations" :options="[
                    ['value' => '', 'label' => 'All operations'],
                    ['value' => 'plan', 'label' => 'Plan'],
                    ['value' => 'apply', 'label' => 'Apply'],
                    ['value' => 'destroy', 'label' => 'Destroy'],
                ]" />
                <x-forms.listbox id="stack" label="Stack" live searchable placeholder="All stacks"
                    :options="[['value' => '', 'label' => 'All stacks'], ...$stackOptions]" />
            </div>

            @if ($jobs->isEmpty())
                <x-empty title="No Terraform jobs yet"
                    description="Jobs appear here when a server on Oracle Cloud is provisioned or destroyed."
                    icon-name="layers" />
            @else
                <div class="flex flex-col">
                    @foreach ($jobs as $job)
                        @php
                            $type = match (true) {
                                $job->isRunning() => 'warning',
                                $job->status === 'succeeded' => 'success',
                                default => 'error',
                            };
                            $duration = $job->started_at && $job->finished_at
                                ? $job->started_at->diffForHumans($job->finished_at, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'short' => true])
                                : null;
                        @endphp
                        <button type="button" wire:key="oci-job-{{ $job->id }}" wire:click="selectJob({{ $job->id }})"
                            class="flex w-full items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 text-left last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:bg-white/[0.03] {{ $selectedJob?->id === $job->id ? 'bg-neutral-50 dark:bg-white/[0.05]' : '' }}">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[13px] font-semibold">{{ ucfirst($job->operation) }} · {{ $job->stack?->name ?? 'Removed stack' }}</span>
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $job->started_at?->diffForHumans() }}
                                    @if ($duration)
                                        · took {{ $duration }}
                                    @endif
                                    · {{ $job->stack?->region }}
                                </span>
                            </div>
                            <x-status-badge :label="str($job->status)->replace('_', ' ')->ucfirst()" :type="$type" />
                        </button>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>

        @if ($selectedJob)
            <x-application.settings-section :title="ucfirst($selectedJob->operation).' log · '.($selectedJob->stack?->name ?? 'Removed stack')"
                :description="'Status: '.str($selectedJob->status)->replace('_', ' ').($selectedJob->finished_at ? ' · finished '.$selectedJob->finished_at->diffForHumans() : '')">
                <x-slot:actions>
                    <div class="flex items-center gap-2">
                        @if ($selectedJob->isRunning())
                            <x-forms.button type="button" wire:click="refreshLiveLog" wire:loading.attr="disabled">
                                Refresh log from OCI
                            </x-forms.button>
                        @endif
                        @if ($selectedJob->job_ocid && $selectedJob->stack)
                            <a href="https://cloud.oracle.com/resourcemanager/jobs/{{ $selectedJob->job_ocid }}?region={{ $selectedJob->stack->region }}"
                                target="_blank" rel="noopener noreferrer" class="button button-secondary text-sm">
                                OCI Console ↗
                            </a>
                        @endif
                    </div>
                </x-slot:actions>
                @if ($selectedJob->log)
                    <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-neutral-950 p-4 font-mono text-xs text-neutral-100">{{ $selectedJob->log }}</pre>
                @else
                    <p class="text-sm text-neutral-500 dark:text-fg-dim">
                        {{ $selectedJob->isRunning() ? 'The job is still running. Use "Refresh log from OCI" to see its progress.' : 'No log was recorded for this job.' }}
                    </p>
                @endif
            </x-application.settings-section>
        @endif
    </x-security.settings-layout>
</div>
