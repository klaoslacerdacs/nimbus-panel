<?php

namespace App\Traits;

use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Services\OciClient;
use Illuminate\Support\Sleep;

/**
 * Runs an OCI Resource Manager job for a stack, records it in oci_stack_jobs and waits for the outcome,
 * so every plan/apply/destroy keeps its status and Terraform log for the Terraform Jobs page.
 */
trait RunsOciStackJobs
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, status: string, job_id: string|null, log: string|null}
     */
    protected function runTrackedStackJob(OciClient $oci, array $config, OciStack $stack, string $operation, ?array $firewallRules = null): array
    {
        $result = $operation === 'destroy'
            ? $oci->runStackJob($config, $stack->stack_ocid, 'DESTROY', true)
            : $oci->runStackJob($config, $stack->stack_ocid, strtoupper($operation));

        if (! isset($result['job_id'])) {
            $reason = 'OCI did not accept the '.$operation.' job'.(isset($result['message']) ? ': '.$result['message'] : '.');
            $this->recordStackJob($stack, $operation, ['status' => 'failed', 'log' => $reason, 'finished_at' => now(), 'firewall_rules' => $firewallRules]);

            return ['ok' => false, 'status' => 'failed', 'job_id' => null, 'log' => $reason];
        }

        $record = $this->recordStackJob($stack, $operation, ['job_ocid' => $result['job_id'], 'status' => 'accepted', 'firewall_rules' => $firewallRules]);
        $status = $this->waitForStackJob($oci, $config, $record);

        $lines = $oci->getJobLogs($config, $record->job_ocid, 2000)['lines'] ?? [];
        $log = $lines === [] ? null : implode("\n", $lines);
        $record->update(['status' => $status, 'log' => $log, 'finished_at' => now()]);

        return ['ok' => $status === 'succeeded', 'status' => $status, 'job_id' => $record->job_ocid, 'log' => $log];
    }

    /**
     * The environment template every stack is created from and updated to (keeps stacks on the current version).
     */
    protected function getTemplateZipBase64(): string
    {
        $path = base_path('terraform/templates/environment.zip');
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("OCI template ZIP not found at {$path}");
        }

        return base64_encode($contents);
    }

    /**
     * Last lines of a Terraform log, sized for the stack summary banner.
     */
    protected function logTail(?string $log, int $lines = 50): ?string
    {
        return $log === null ? null : implode("\n", array_slice(explode("\n", $log), -$lines));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function waitForStackJob(OciClient $oci, array $config, OciStackJob $record): string
    {
        $deadline = now()->addMinutes(30);

        while (now()->lt($deadline)) {
            $status = strtolower($oci->getStackJob($config, $record->job_ocid)['status'] ?? '');

            // An empty status means the OCI API was unreachable (already retried): keep polling.
            if ($status !== '' && $status !== $record->status) {
                $record->update(['status' => $status]);
            }
            if ($status !== '' && ! in_array($status, OciStackJob::RUNNING, true)) {
                return $status;
            }

            Sleep::for(30)->seconds();
        }

        return 'timed_out';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordStackJob(OciStack $stack, string $operation, array $attributes): OciStackJob
    {
        return OciStackJob::create([
            'team_id' => $stack->team_id,
            'oci_stack_id' => $stack->id,
            'operation' => $operation,
            'started_at' => now(),
            ...$attributes,
        ]);
    }
}
