<?php

namespace App\Services;

use App\Services\Oci\OciApiClient;
use App\Services\Oci\OciComputeService;
use App\Services\Oci\OciException;
use App\Services\Oci\OciMonitoringService;
use App\Services\Oci\OciResourceManagerService;
use Throwable;

/**
 * Thin facade over the native OCI services. Keeps the exact public surface the
 * Livewire components and jobs already call: unwrapped `data` array on success,
 * {success:false, error:true, message, code, details} on failure. The former
 * Python HTTP bridge has been replaced by in-process REST calls.
 */
class OciBridgeClient
{
    public function __construct(
        private ?OciComputeService $compute = null,
        private ?OciMonitoringService $monitoring = null,
        private ?OciResourceManagerService $resourceManager = null,
        private ?OciApiClient $api = null,
    ) {
        $this->compute ??= app(OciComputeService::class);
        $this->monitoring ??= app(OciMonitoringService::class);
        $this->resourceManager ??= app(OciResourceManagerService::class);
        $this->api ??= app(OciApiClient::class);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function validateConnection(array $config): array
    {
        return $this->guard(function () use ($config): array {
            $tenancy = $this->api->get($config, 'identity', $config['region'], "/20160918/tenancies/{$config['tenancy_id']}");

            return ['ok' => true, 'tenancy_ocid' => $tenancy['id'] ?? $config['tenancy_id']];
        });
    }

    public function listRegions(): array
    {
        return array_map(fn (string $name): array => ['name' => $name], config('oci.regions', []));
    }

    public function listCompartments(array $config, string $region): array
    {
        return $this->guard(fn () => $this->compute->listCompartments($config, $region));
    }

    public function listInstances(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listInstances($config, $compartmentId, $region));
    }

    public function listAvailabilityDomains(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listAvailabilityDomains($config, $compartmentId, $region));
    }

    public function listShapes(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listShapes($config, $compartmentId, $region));
    }

    public function listImages(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listImages($config, $compartmentId, $region));
    }

    public function listSubnets(array $config, string $vcnId, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listSubnets($config, $vcnId, $compartmentId, $region));
    }

    public function listSubnetsByCompartment(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listSubnetsByCompartment($config, $compartmentId, $region));
    }

    public function listVcns(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listVcns($config, $compartmentId, $region));
    }

    public function listNsgRules(array $config, string $nsgId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listNsgRules($config, $nsgId, $region));
    }

    public function addNsgRules(array $config, string $nsgId, array $rules, string $region): array
    {
        return $this->guard(fn () => $this->compute->addNsgRules($config, $nsgId, $rules, $region));
    }

    public function removeNsgRules(array $config, string $nsgId, array $ruleIds, string $region): array
    {
        return $this->guard(fn () => $this->compute->removeNsgRules($config, $nsgId, $ruleIds, $region));
    }

    public function createStack(array $config, string $name, string $compartmentId, string $configSourceType, array $configSourceParams, array $variables = []): array
    {
        return $this->guard(fn () => $this->resourceManager->createStack($config, $name, $compartmentId, $configSourceType, $configSourceParams, $variables));
    }

    public function runStackJob(array $config, string $stackId, string $operation, bool $confirmDestroy = false): array
    {
        return $this->guard(fn () => $this->resourceManager->runJob($config, $stackId, $operation, $confirmDestroy));
    }

    public function getStackJob(array $config, string $jobId): array
    {
        return $this->guard(fn () => $this->resourceManager->getJob($config, $jobId));
    }

    public function getStackOutputs(array $config, string $jobId): array
    {
        return $this->guard(fn () => $this->resourceManager->getStackOutputs($config, $jobId));
    }

    public function getJobLogs(array $config, string $jobId): array
    {
        return $this->guard(fn () => $this->resourceManager->getJobLogs($config, $jobId));
    }

    public function getInstanceStatus(array $config, string $instanceId, string $region): array
    {
        return $this->guard(fn () => $this->compute->getInstanceStatus($config, $instanceId, $region));
    }

    public function getInstanceMetrics(array $config, string $instanceId, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->monitoring->getInstanceMetrics($config, $instanceId, $compartmentId, $region));
    }

    public function instanceAction(array $config, string $instanceId, string $region, string $action): array
    {
        return $this->guard(fn () => $this->compute->instanceAction($config, $instanceId, $region, $action));
    }

    public function listVolumeAttachments(array $config, string $compartmentId, string $instanceId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listVolumeAttachments($config, $compartmentId, $instanceId, $region));
    }

    public function attachVolume(array $config, string $instanceId, string $volumeId, string $region, bool $readOnly = false): array
    {
        return $this->guard(fn () => $this->compute->attachVolume($config, $instanceId, $volumeId, $region, $readOnly));
    }

    public function detachVolume(array $config, string $volumeAttachmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->detachVolume($config, $volumeAttachmentId, $region));
    }

    public function listLoadBalancers(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listLoadBalancers($config, $compartmentId, $region));
    }

    public function listAutonomousDatabases(array $config, string $compartmentId, string $region): array
    {
        return $this->guard(fn () => $this->compute->listAutonomousDatabases($config, $compartmentId, $region));
    }

    /**
     * Run a delegate, normalizing any OCI failure into the legacy error shape.
     *
     * @param  callable(): array<mixed>  $fn
     */
    private function guard(callable $fn): array
    {
        try {
            return $fn();
        } catch (OciException $e) {
            return $e->toArray();
        } catch (Throwable) {
            // Never leak internals (stack traces may reference key material paths).
            return ['success' => false, 'error' => true, 'message' => 'OCI request failed', 'code' => 500, 'details' => null];
        }
    }
}
