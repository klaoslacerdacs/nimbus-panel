<?php

namespace App\Services\Oci;

/**
 * Terraform stacks via OCI Resource Manager: create stacks, run PLAN/APPLY/
 * DESTROY jobs, read state outputs and job logs. Region is taken from the
 * connection config (RM callers don't pass it).
 */
class OciResourceManagerService
{
    private const RM = '/20180917';

    public function __construct(private OciApiClient $api) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $configSourceParams
     * @param  array<string, string>  $variables
     * @return array{stack_id: string, display_name: string|null}
     */
    public function createStack(array $config, string $name, string $compartmentId, string $configSourceType, array $configSourceParams, array $variables = []): array
    {
        $configSource = match ($configSourceType) {
            'ZIP_UPLOAD' => [
                'configSourceType' => 'ZIP_UPLOAD',
                'zipFileBase64Encoded' => $configSourceParams['zip_file_base64_encoded'] ?? '',
            ],
            'GIT_CONFIG_SOURCE' => ['configSourceType' => 'GIT_CONFIG_SOURCE'] + $configSourceParams,
            default => throw new OciException("Unsupported config_source_type: {$configSourceType}", 422, 'validation'),
        };

        $resp = $this->api->post($config, 'resourcemanager', $this->region($config), self::RM.'/stacks', [
            'displayName' => $name,
            'compartmentId' => $compartmentId,
            'configSource' => $configSource,
            'variables' => (object) $variables,
            'terraformVersion' => config('oci.terraform_version', '1.5.x'),
        ]);

        return ['stack_id' => $resp['id'], 'display_name' => $resp['displayName'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{job_id: string, operation: string}
     */
    public function runJob(array $config, string $stackId, string $operation, bool $confirmDestroy = false): array
    {
        $operation = strtoupper($operation);

        // Trust boundary: never destroy without explicit confirmation.
        if ($operation === 'DESTROY' && ! $confirmDestroy) {
            throw new OciException('Confirmation required for destroy operation', 400, 'validation');
        }

        $jobOperationDetails = match ($operation) {
            'PLAN' => ['operation' => 'PLAN'],
            'APPLY' => ['operation' => 'APPLY', 'executionPlanStrategy' => 'AUTO_APPROVED'],
            'DESTROY' => ['operation' => 'DESTROY', 'executionPlanStrategy' => 'AUTO_APPROVED'],
            default => throw new OciException("Invalid operation: {$operation}", 422, 'validation'),
        };

        $resp = $this->api->post($config, 'resourcemanager', $this->region($config), self::RM.'/jobs', [
            'stackId' => $stackId,
            'jobOperationDetails' => $jobOperationDetails,
        ]);

        return ['job_id' => $resp['id'], 'operation' => strtolower($operation)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{status: string, lifecycle_state: string}
     */
    public function getJob(array $config, string $jobId): array
    {
        $resp = $this->api->get($config, 'resourcemanager', $this->region($config), self::RM."/jobs/{$jobId}");
        $state = $resp['lifecycleState'] ?? '';

        return ['status' => strtolower($state), 'lifecycle_state' => $state];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function getStackOutputs(array $config, string $jobId): array
    {
        $raw = $this->api->rawGet($config, 'resourcemanager', $this->region($config), self::RM."/jobs/{$jobId}/tfState");
        $state = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OciException('Failed to parse Terraform state: '.json_last_error_msg(), 502, 'parse');
        }
        $outputs = is_array($state) ? ($state['outputs'] ?? []) : [];

        $result = [];
        foreach ($outputs as $key => $value) {
            $result[$key] = $value['value'] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{lines: array<int, string>}
     */
    public function getJobLogs(array $config, string $jobId, int $maxLines = 50): array
    {
        $rows = $this->api->paginate($config, 'resourcemanager', $this->region($config), self::RM."/jobs/{$jobId}/logs");

        $lines = [];
        foreach ($rows as $entry) {
            if (! empty($entry['message'])) {
                $lines[] = $entry['message'];
            }
        }

        return ['lines' => array_slice($lines, -$maxLines)];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function region(array $config): string
    {
        return $config['region'] ?? throw new OciException('OCI region missing from config', 422, 'config');
    }
}
