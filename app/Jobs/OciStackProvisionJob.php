<?php

namespace App\Jobs;

use App\Events\OciStackStatusChanged;
use App\Models\OciStack;
use App\Models\OciStackJob;
use App\Models\Server;
use App\Services\OciClient;
use App\Traits\RunsOciStackJobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class OciStackProvisionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOciStackJobs, SerializesModels;

    // ponytail: blocks queue worker for up to 30min — move to polling job if throughput matters
    // Plan (≤30m) + apply (≤30m) + SSH wait (≤5m).
    public int $timeout = 3900;

    public int $tries = 1;

    public int $uniqueFor = 3900;

    public function __construct(public OciStack $ociStack)
    {
        $this->onQueue('oci');
    }

    public function uniqueId(): string
    {
        return (string) $this->ociStack->id;
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ["oci-stack:{$this->ociStack->id}", "team:{$this->ociStack->team_id}"];
    }

    public function handle(OciClient $oci): void
    {
        $stack = $this->ociStack->loadMissing('ociConnection');

        if (! $stack->ociConnection) {
            $this->markFailed($stack, 'OCI connection not found');

            return;
        }

        $config = $stack->ociConnection->toApiConfig($stack->region);

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

            $result = $oci->createStack(
                $config,
                $stack->name,
                $stackCompartmentId,
                'ZIP_UPLOAD',
                ['zip_file_base64_encoded' => $zipBase64],
                // Firewall rules are Terraform resources (ingress_rules_json), so they are versioned with the stack.
                $stack->terraformVariables()
            );

            if (! isset($result['stack_id'])) {
                $this->markFailed($stack, 'Stack creation failed');

                return;
            }

            $stack->update(['stack_ocid' => $result['stack_id'], 'status' => 'planning', 'last_plan_summary' => 'Running Terraform plan...']);
            OciStackStatusChanged::dispatch($stack->team_id);
        }

        $rules = $stack->effectiveFirewallRules();
        $plan = $this->runTrackedStackJob($oci, $config, $stack, 'plan', $rules);
        if (! $plan['ok']) {
            $this->markFailed($stack, 'Terraform plan failed', $this->logTail($plan['log']));

            return;
        }

        $stack->update(['status' => 'applying', 'last_plan_summary' => 'Applying Terraform — creating OCI resources (this can take up to 30 min)...']);
        OciStackStatusChanged::dispatch($stack->team_id);

        $apply = $this->runTrackedStackJob($oci, $config, $stack, 'apply', $rules);
        if (! $apply['ok']) {
            $this->markFailed($stack, 'Terraform apply failed', $this->logTail($apply['log']));

            return;
        }

        $outputs = $oci->getStackOutputs($config, $apply['job_id']);

        $serverUpdate = [];
        if (! empty($outputs['public_ip'])) {
            $serverUpdate['ip'] = $outputs['public_ip'];
        }
        if (! empty($outputs['instance_id'])) {
            $serverUpdate['oci_instance_id'] = $outputs['instance_id'];
        }
        if (! empty($outputs['compartment_id'])) {
            $serverUpdate['oci_compartment_id'] = $outputs['compartment_id'];
        }
        if ($serverUpdate) {
            $stack->server?->update($serverUpdate);
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
        if ($nsgId) {
            $stack->update(['nsg_ocid' => $nsgId]);
        }

        // The server may have been deleted while Terraform ran.
        $server = $stack->server;
        if ($server && isset($outputs['public_ip']) && Server::query()->whereKey($server->getKey())->exists()) {
            $this->waitForSsh($server);
            ValidateAndInstallServerJob::dispatch($server);
            Log::info('OciStackProvisionJob: dispatched SSH validation', ['server_id' => $server->id]);
        }
    }

    /**
     * Horizon kills the job on timeout without reaching markFailed(); never leave the stack "applying".
     */
    public function failed(\Throwable $exception): void
    {
        OciStackJob::failOpenJobs($this->ociStack, 'Provisioning stopped: '.$exception->getMessage());
        $this->markFailed($this->ociStack, 'Provisioning stopped: '.$exception->getMessage());
    }

    private function markFailed(OciStack $stack, string $reason, ?string $logs = null): void
    {
        $summary = $logs ? $reason."\n\n".$logs : $reason;
        $stack->update(['status' => 'failed', 'last_plan_summary' => $summary]);
        OciStackStatusChanged::dispatch($stack->team_id);
        Log::error('OciStackProvisionJob failed', ['oci_stack_id' => $stack->id, 'reason' => $reason]);
        auditLog('oci.stack.provision_failed', ['oci_stack_id' => $stack->id, 'reason' => $reason], 'error');
    }

    /**
     * A fresh VM refuses SSH until boot and cloud-init (which installs the authorized key) finish.
     * ValidateAndInstallServerJob does not retry a failed connection, so wait here first.
     */
    private function waitForSsh(Server $server): void
    {
        $deadline = time() + (int) config('oci.ssh_wait_seconds', 300);

        while (time() < $deadline) {
            if ($server->validateConnection(justCheckingNewKey: true)['uptime']) {
                return;
            }
            Sleep::for(15)->seconds();
        }
    }
}
