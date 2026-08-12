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

    public function validateConnection(array $credentials): array
    {
        return $this->request('POST', '/v1/connections/validate', $credentials);
    }

    public function listRegions(): array
    {
        return $this->request('GET', '/v1/regions');
    }

    public function listCompartments(string $region): array
    {
        return $this->request('GET', "/v1/compartments?region={$region}");
    }

    public function listInstances(string $compartmentId, string $region): array
    {
        return $this->request('GET', "/v1/compute/instances?compartment_id={$compartmentId}&region={$region}");
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
            } catch (ConnectionException $e) {
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    return [
                        'success' => false,
                        'error' => true,
                        'message' => 'Bridge unreachable after retries',
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
