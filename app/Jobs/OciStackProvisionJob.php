<?php

namespace App\Jobs;

use App\Events\OciStackStatusChanged;
use App\Models\OciStack;
use App\Services\OciBridgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OciStackProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ponytail: blocks queue worker for up to 30min — move to polling job if throughput matters
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public OciStack $ociStack) {}

    public function handle(OciBridgeClient $bridge): void
    {
        $stack = $this->ociStack->loadMissing('ociConnection');

        if (! $stack->ociConnection) {
            $this->markFailed($stack, 'OCI connection not found');

            return;
        }

        $config = $stack->ociConnection->toBridgeConfig();

        // When creating a new compartment, the RM stack itself lives in parent/tenancy root
        $stackCompartmentId = $stack->compartment_ocid
            ?: ($stack->tf_vars['parent_compartment_ocid'] ?? null)
            ?: $stack->ociConnection->tenancy_ocid;

        if (! $stack->stack_ocid) {
            try {
                $zipBase64 = $this->getTemplateZipBase64();
            } catch (\RuntimeException $e) {
                $this->markFailed($stack, $e->getMessage());

                return;
            }

            $result = $bridge->createStack(
                $config,
                $stack->name,
                $stackCompartmentId,
                'ZIP_UPLOAD',
                ['zip_file_base64_encoded' => $zipBase64],
                $stack->tf_vars ?? []
            );

            if (! isset($result['stack_id'])) {
                $this->markFailed($stack, 'Stack creation failed');

                return;
            }

            $stack->update(['stack_ocid' => $result['stack_id'], 'status' => 'planning', 'last_plan_summary' => 'Running Terraform plan...']);
            OciStackStatusChanged::dispatch($stack->team_id);
        }

        $plan = $bridge->runStackJob($config, $stack->stack_ocid, 'PLAN');
        if (! isset($plan['job_id'])) {
            $this->markFailed($stack, 'Plan job creation failed');

            return;
        }

        $planLogs = null;
        if (! $this->pollUntilDone($bridge, $config, $plan['job_id'], $planLogs)) {
            $this->markFailed($stack, 'Terraform plan failed', $planLogs);

            return;
        }

        $stack->update(['status' => 'applying', 'last_plan_summary' => 'Applying Terraform — creating OCI resources (this can take up to 30 min)...']);
        OciStackStatusChanged::dispatch($stack->team_id);

        $apply = $bridge->runStackJob($config, $stack->stack_ocid, 'APPLY');
        if (! isset($apply['job_id'])) {
            $this->markFailed($stack, 'Apply job creation failed');

            return;
        }

        $applyLogs = null;
        if (! $this->pollUntilDone($bridge, $config, $apply['job_id'], $applyLogs)) {
            $this->markFailed($stack, 'Terraform apply failed', $applyLogs);

            return;
        }

        $outputs = $bridge->getStackOutputs($config, $apply['job_id']);
        if (isset($outputs['public_ip'])) {
            $stack->server?->update(['ip' => $outputs['public_ip']]);
        }

        $stackUpdate = ['status' => 'provisioned', 'last_plan_summary' => null, 'oci_instance_id' => $outputs['instance_id'] ?? null];
        if (! empty($outputs['vcn_id'])) {
            $stackUpdate['vcn_ocid'] = $outputs['vcn_id'];
        }
        if (! empty($outputs['compartment_id']) && ! $stack->compartment_ocid) {
            $stackUpdate['compartment_ocid'] = $outputs['compartment_id'];
        }
        $stack->update($stackUpdate);
        OciStackStatusChanged::dispatch($stack->team_id);

        $nsgId = $outputs['nsg_id'] ?? null;
        if ($nsgId && $stack->firewall_rules) {
            $stack->update(['nsg_ocid' => $nsgId]);
            $bridge->addNsgRules($config, $nsgId, $stack->firewall_rules);
        }

        if ($stack->server && isset($outputs['public_ip'])) {
            ValidateAndInstallServerJob::dispatch($stack->server);
            Log::info('OciStackProvisionJob: dispatched SSH validation', ['server_id' => $stack->server->id]);
        }
    }

    private function pollUntilDone(OciBridgeClient $bridge, array $config, string $jobId, ?string &$errorLogs = null): bool
    {
        $deadline = now()->addMinutes(30);

        while (now()->lt($deadline)) {
            $result = $bridge->getStackJob($config, $jobId);
            $status = strtolower($result['status'] ?? '');

            if ($status === 'succeeded') {
                return true;
            }

            if ($status === '') {
                // Bridge unreachable (already retried 3x) — treat as transient, keep polling
                sleep(30);

                continue;
            }

            if (! in_array($status, ['in_progress', 'accepted', 'waiting'])) {
                $logResult = $bridge->getJobLogs($config, $jobId);
                if (! empty($logResult['lines'])) {
                    $errorLogs = implode("\n", $logResult['lines']);
                }

                return false;
            }

            sleep(30);
        }

        return false;
    }

    private function getTemplateZipBase64(): string
    {
        $path = base_path('terraform/templates/environment.zip');
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("OCI template ZIP not found at {$path}");
        }

        return base64_encode($contents);
    }

    private function markFailed(OciStack $stack, string $reason, ?string $logs = null): void
    {
        $summary = $logs ? $reason."\n\n".$logs : $reason;
        $stack->update(['status' => 'failed', 'last_plan_summary' => $summary]);
        OciStackStatusChanged::dispatch($stack->team_id);
        Log::error('OciStackProvisionJob failed', ['oci_stack_id' => $stack->id, 'reason' => $reason]);
        auditLog('oci.stack.provision_failed', ['oci_stack_id' => $stack->id, 'reason' => $reason], 'error');
    }
}
