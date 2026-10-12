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

    // Query-string values are untyped: keep them loose and normalize before use (?job=abc must not 500).
    #[Url]
    public mixed $stack = '';

    #[Url]
    public mixed $operation = '';

    #[Url(as: 'job')]
    public mixed $selectedJobId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', OciStack::class);
        $this->stack = is_string($this->stack) ? $this->stack : '';
        $this->operation = in_array($this->operation, ['plan', 'apply', 'destroy'], true) ? $this->operation : '';
        $this->selectedJobId = is_numeric($this->selectedJobId) ? (int) $this->selectedJobId : null;
    }

    public function selectJob(int $jobId): void
    {
        $job = $this->findJob($jobId);
        if ($job?->stack) {
            $this->authorize('view', $job->stack);
        }
        $this->selectedJobId = $job?->id;
    }

    /**
     * Pull the current log of a running job straight from OCI and keep it on the record.
     */
    public function refreshLiveLog(): void
    {
        $job = is_numeric($this->selectedJobId) ? $this->findJob((int) $this->selectedJobId) : null;
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

    /**
     * Roll the stack's firewall back to the rules this (successful apply) job applied.
     */
    public function restoreSelectedVersion(): void
    {
        $job = is_numeric($this->selectedJobId) ? $this->findJob((int) $this->selectedJobId) : null;
        if (! $job?->stack || $job->operation !== 'apply' || $job->status !== 'succeeded' || $job->firewall_rules === null) {
            return;
        }
        $this->authorize('update', $job->stack);

        $result = $job->stack->queueFirewallChange($job->firewall_rules);
        if ($result !== 'queued') {
            $this->dispatch('error', OciStack::firewallChangeError($result));

            return;
        }

        auditLog('ui.oci_firewall.version_restored', ['oci_stack_id' => $job->stack->id, 'oci_stack_job_id' => $job->id]);
        $this->dispatch('success', 'Firewall version queued: Terraform runs plan → apply.');
    }

    private function findJob(int $jobId): ?OciStackJob
    {
        return OciStackJob::ownedByCurrentTeam()->with('stack.ociConnection')->find($jobId);
    }

    public function render(): Factory|View
    {
        $jobs = OciStackJob::ownedByCurrentTeam()
            ->with('stack:id,uuid,name,region')
            ->when(in_array($this->operation, ['plan', 'apply', 'destroy'], true), fn ($query) => $query->where('operation', $this->operation))
            ->when(is_string($this->stack) && $this->stack !== '', fn ($query) => $query->whereHas('stack', fn ($stack) => $stack->where('uuid', $this->stack)))
            ->latest('id')
            ->limit(100)
            ->get();

        return view('livewire.security.oci-stack-jobs', [
            'jobs' => $jobs,
            'selectedJob' => is_numeric($this->selectedJobId) ? $this->findJob((int) $this->selectedJobId) : null,
            'stackOptions' => OciStack::ownedByCurrentTeam()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (OciStack $stack): array => ['value' => $stack->uuid, 'label' => $stack->name])->all(),
        ]);
    }
}
