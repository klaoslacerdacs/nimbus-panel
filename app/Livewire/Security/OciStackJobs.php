<?php

namespace App\Livewire\Security;

use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Services\OciClient;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * History of OCI Resource Manager jobs (plan / apply / destroy) with their Terraform logs.
 */
class OciStackJobs extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $stack = '';

    #[Url]
    public string $operation = '';

    #[Url(as: 'job')]
    public ?int $selectedJobId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', OciStack::class);
    }

    public function selectJob(int $jobId): void
    {
        $this->selectedJobId = $this->findJob($jobId)?->id;
    }

    /**
     * Pull the current log of a running job straight from OCI and keep it on the record.
     */
    public function refreshLiveLog(): void
    {
        $job = $this->selectedJobId ? $this->findJob($this->selectedJobId) : null;
        if (! $job?->job_ocid || ! $job->stack?->ociConnection) {
            return;
        }
        $this->authorize('view', $job->stack);

        $result = app(OciClient::class)->getJobLogs($job->stack->ociConnection->toApiConfig($job->stack->region), $job->job_ocid, 2000);
        if (! empty($result['error'])) {
            $this->dispatch('error', 'Could not read the job log from OCI.', e($result['message'] ?? ''));

            return;
        }

        $job->update(['log' => implode("\n", $result['lines'] ?? [])]);
    }

    private function findJob(int $jobId): ?OciStackJob
    {
        return OciStackJob::ownedByCurrentTeam()->with('stack.ociConnection')->find($jobId);
    }

    public function render(): Factory|View
    {
        $jobs = OciStackJob::ownedByCurrentTeam()
            ->with('stack:id,uuid,name,region')
            ->when($this->operation !== '', fn ($query) => $query->where('operation', $this->operation))
            ->when($this->stack !== '', fn ($query) => $query->whereHas('stack', fn ($stack) => $stack->where('uuid', $this->stack)))
            ->latest('id')
            ->limit(100)
            ->get();

        return view('livewire.security.oci-stack-jobs', [
            'jobs' => $jobs,
            'selectedJob' => $this->selectedJobId ? $this->findJob($this->selectedJobId) : null,
            'stackOptions' => OciStack::ownedByCurrentTeam()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (OciStack $stack): array => ['value' => $stack->uuid, 'label' => $stack->name])->all(),
        ]);
    }
}
