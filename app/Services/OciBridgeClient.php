<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class OciBridgeClient
{
    private string $baseUrl;

    private int $timeout;

    private int $maxRetries;

    private ?string $authToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(Config::get('oci.bridge_url', 'http://localhost:8000'), '/');
        $this->timeout = (int) Config::get('oci.bridge_timeout', 30);
        $this->maxRetries = (int) Config::get('oci.bridge_retry_max', 3);
        $this->authToken = Config::get('oci.bridge_auth_token');
    }

    public function validateConnection(array $config): array
    {
        // Bridge expects {"config": {...}} matching _parse_config() key names.
        return $this->request('POST', '/v1/connections/validate', ['config' => $config]);
    }

    public function listRegions(): array
    {
        return $this->request('GET', '/v1/regions');
    }

    public function listCompartments(array $config, string $region): array
    {
        return $this->request('POST', '/v1/compartments', compact('config', 'region'));
    }

    public function listInstances(array $config, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/instances', ['config' => $config, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listAvailabilityDomains(array $config, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/availability-domains', ['config' => $config, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listShapes(array $config, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/shapes', ['config' => $config, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listImages(array $config, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/images', ['config' => $config, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listSubnets(array $config, string $vcnId, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/subnets', ['config' => $config, 'vcn_id' => $vcnId, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listSubnetsByCompartment(array $config, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/subnets-by-compartment', ['config' => $config, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listVcns(array $config, string $compartmentId, string $region): array
    {
        return $this->request('POST', '/v1/compute/vcns', ['config' => $config, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function listNsgRules(array $config, string $nsgId, string $region): array
    {
        return $this->request('POST', '/v1/compute/nsg/rules', ['config' => $config, 'nsg_id' => $nsgId, 'region' => $region]);
    }

    public function addNsgRules(array $config, string $nsgId, array $rules): array
    {
        return $this->request('POST', '/v1/compute/nsg/rules/add', ['config' => $config, 'nsg_id' => $nsgId, 'rules' => $rules]);
    }

    public function removeNsgRules(array $config, string $nsgId, array $ruleIds): array
    {
        return $this->request('POST', '/v1/compute/nsg/rules/remove', ['config' => $config, 'nsg_id' => $nsgId, 'rule_ids' => $ruleIds]);
    }

    public function createStack(array $config, string $name, string $compartmentId, string $configSourceType, array $configSourceParams, array $variables = []): array
    {
        return $this->request('POST', '/v1/stacks', [
            'config' => $config,
            'name' => $name,
            'compartment_id' => $compartmentId,
            'config_source_type' => $configSourceType,
            'config_source_params' => $configSourceParams,
            'variables' => $variables,
        ]);
    }

    public function runStackJob(array $config, string $stackId, string $operation, bool $confirmDestroy = false): array
    {
        return $this->request('POST', "/v1/stacks/{$stackId}/jobs", [
            'config' => $config,
            'operation' => $operation,
            'confirm_destroy' => $confirmDestroy,
        ]);
    }

    // ponytail: POST instead of GET to avoid credentials in URL/logs
    public function getStackJob(array $config, string $jobId): array
    {
        return $this->request('POST', "/v1/stacks/jobs/{$jobId}/status", compact('config'));
    }

    public function getStackOutputs(array $config, string $jobId): array
    {
        return $this->request('POST', "/v1/stacks/jobs/{$jobId}/outputs", compact('config'));
    }

    public function getInstanceStatus(array $config, string $instanceId, string $region): array
    {
        return $this->request('POST', "/v1/compute/instances/{$instanceId}/status", ['config' => $config, 'instance_id' => $instanceId, 'region' => $region]);
    }

    public function getInstanceMetrics(array $config, string $instanceId, string $compartmentId, string $region): array
    {
        return $this->request('POST', "/v1/compute/instances/{$instanceId}/metrics", ['config' => $config, 'instance_id' => $instanceId, 'compartment_id' => $compartmentId, 'region' => $region]);
    }

    public function instanceAction(array $config, string $instanceId, string $region, string $action): array
    {
        return $this->request('POST', "/v1/compute/instances/{$instanceId}/action", ['config' => $config, 'instance_id' => $instanceId, 'region' => $region, 'action' => $action]);
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->authToken) {
            $headers['Authorization'] = 'Bearer '.$this->authToken;
        }

        $attempt = 0;
        while (true) {
            try {
                $http = Http::timeout($this->timeout)->withHeaders($headers);

                $response = $method === 'POST'
                    ? $http->post($this->baseUrl.$endpoint, $data)
                    : $http->get($this->baseUrl.$endpoint);

                if ($response->successful()) {
                    return $response->json('data', []);
                }

                return $this->normalizeError($response->status(), $response->json());
            } catch (\Throwable $e) {
                // Covers ConnectionException (connect) and RequestException (read timeout)
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    return [
                        'success' => false,
                        'error' => true,
                        'message' => $e instanceof ConnectionException ? 'Bridge unreachable after retries' : $e->getMessage(),
                        'code' => 503,
                        'details' => null,
                    ];
                }
                usleep(100000 * (2 ** $attempt)); // ponytail: exponential backoff, max ~3.2s
            }
        }
    }

    private function normalizeError(int $status, ?array $body): array
    {
        return [
            'success' => false,
            'error' => true,
            'message' => $body['message'] ?? 'Unknown OCI bridge error',
            'code' => $body['code'] ?? $status,
            'details' => $body['details'] ?? null,
        ];
    }
}
