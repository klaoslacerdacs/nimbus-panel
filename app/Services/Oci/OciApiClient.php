<?php

namespace App\Services\Oci;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Base OCI REST transport: resolves the per-service regional host, signs each
 * request, retries 429/5xx with backoff, follows opc-next-page, and raises a
 * typed OciException carrying the normalized ServiceError shape.
 */
class OciApiClient
{
    public function __construct(private OciRequestSigner $signer) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(array $config, string $service, string $region, string $path, array $query = []): array
    {
        return $this->send($config, 'GET', $service, $region, $path, $query, null)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function post(array $config, string $service, string $region, string $path, ?array $body = null, array $query = []): array
    {
        $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->send($config, 'POST', $service, $region, $path, $query, $json)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function delete(array $config, string $service, string $region, string $path, array $query = []): array
    {
        return $this->send($config, 'DELETE', $service, $region, $path, $query, null)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $query
     */
    public function rawGet(array $config, string $service, string $region, string $path, array $query = []): string
    {
        return $this->send($config, 'GET', $service, $region, $path, $query, null, '*/*')->body();
    }

    /**
     * Follows opc-next-page and merges every page into a single list.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function paginate(array $config, string $service, string $region, string $path, array $query = []): array
    {
        $items = [];
        $page = null;
        $fetched = 0;

        do {
            $q = $query;
            if ($page !== null) {
                $q['page'] = $page;
            }
            $response = $this->send($config, 'GET', $service, $region, $path, $q, null);
            $data = $response->json() ?? [];
            if (is_array($data)) {
                $items = array_merge($items, $data);
            }
            $page = $response->header('opc-next-page') ?: null;
        } while ($page !== null && ++$fetched < 200);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $query
     */
    private function send(array $config, string $method, string $service, string $region, string $path, array $query, ?string $body, string $accept = 'application/json'): Response
    {
        $url = $this->buildUrl($service, $region, $path, $query);
        $timeout = (int) config('oci.timeout', 30);
        $maxRetries = (int) config('oci.retry_max', 3);
        $attempt = 0;

        while (true) {
            $headers = $this->signer->signHeaders($config, $method, $url, $body);
            $request = Http::timeout($timeout)->withHeaders($headers)->accept($accept);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'DELETE' => $request->delete($url),
                default => $request->withBody($body ?? '', 'application/json')->send(strtoupper($method), $url),
            };

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            if ($status === 401 && ($config['auth_mode'] ?? null) === 'instance_principal' && ! isset($refreshedToken)) {
                // Security token revoked or expired early: re-federate once.
                OciInstancePrincipal::forget();
                $refreshedToken = true;

                continue;
            }

            if (($status === 429 || $status >= 500) && $attempt < $maxRetries) {
                $attempt++;
                usleep(100000 * (2 ** $attempt)); // exponential backoff, ~0.2s→3.2s
                // ponytail: sync backoff blocks FPM/Horizon — upgrade to Guzzle retry middleware when 429 storms appear

                continue;
            }

            throw $this->serviceError($response);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildUrl(string $service, string $region, string $path, array $query): string
    {
        $template = config("oci.endpoints.{$service}");
        if (! is_string($template)) {
            throw new OciException("Unknown OCI service: {$service}", 500, 'config');
        }

        $url = rtrim(str_replace('{region}', $region, $template), '/').$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function serviceError(Response $response): OciException
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['message'] ?? 'OCI request failed') : 'OCI request failed';
        $code = is_array($body) ? ($body['code'] ?? null) : null;

        return new OciException($message, $response->status(), $code);
    }
}
