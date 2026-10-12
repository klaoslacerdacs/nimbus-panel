<?php

namespace App\Services\Oci;

use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Signs OCI REST requests with the draft-cavage v1 HTTP Signature scheme
 * (RSA-SHA256). Stateless: no key material is stored on the instance, logged,
 * or serialized. API keys are loaded here; Instance Principal session keys come
 * from OciInstancePrincipal.
 */
class OciRequestSigner
{
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private const FINGERPRINT = '/^[0-9a-f]{2}(:[0-9a-f]{2})+$/i';

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string> Headers to merge into the outgoing request.
     */
    public function signHeaders(#[SensitiveParameter] array $config, string $method, string $url, ?string $body): array
    {
        if (($config['auth_mode'] ?? 'api_key') === 'instance_principal') {
            [$keyId, $key] = app(OciInstancePrincipal::class)->credentials();

            return $this->sign($keyId, $key, $method, $url, $body);
        }

        $this->assertApiKeyConfig($config);
        $key = $this->loadKey($config['key_content'] ?? '', $config['passphrase'] ?? null);

        return $this->sign("{$config['tenancy_id']}/{$config['user_id']}/{$config['fingerprint']}", $key, $method, $url, $body);
    }

    /**
     * @return array<string, string>
     */
    public function sign(string $keyId, #[SensitiveParameter] OpenSSLAsymmetricKey $key, string $method, string $url, ?string $body): array
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $signingString = $this->buildSigningString($date, $method, $url, $body);

        if (! openssl_sign($signingString, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new OciException('Failed to sign OCI request', 500, 'signing');
        }

        $headerNames = $this->isBodyMethod($method)
            ? 'date (request-target) host content-length content-type x-content-sha256'
            : 'date (request-target) host';

        $headers = [
            'Date' => $date,
            'Authorization' => sprintf(
                'Signature version="1",keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
                $keyId,
                $headerNames,
                base64_encode($signature),
            ),
        ];

        if ($this->isBodyMethod($method)) {
            $headers['x-content-sha256'] = base64_encode(hash('sha256', $body ?? '', true));
            $headers['Content-Type'] = 'application/json';
            $headers['Content-Length'] = (string) strlen($body ?? '');
        }

        return $headers;
    }

    /**
     * Pure signing-string construction — no secrets involved, byte-exact for tests.
     */
    public function buildSigningString(string $date, string $method, string $url, ?string $body): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        $requestTarget = strtolower($method).' '.$path.($query !== '' ? '?'.$query : '');

        $lines = [
            'date: '.$date,
            '(request-target): '.$requestTarget,
            'host: '.$host,
        ];

        if ($this->isBodyMethod($method)) {
            $body ??= '';
            $lines[] = 'content-length: '.strlen($body);
            $lines[] = 'content-type: application/json';
            $lines[] = 'x-content-sha256: '.base64_encode(hash('sha256', $body, true));
        }

        return implode("\n", $lines);
    }

    private function isBodyMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::BODY_METHODS, true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertApiKeyConfig(array $config): void
    {
        if (! str_starts_with((string) ($config['tenancy_id'] ?? ''), 'ocid1.tenancy.')) {
            throw new OciException('OCI tenancy OCID is invalid', 422, 'config');
        }
        if (! str_starts_with((string) ($config['user_id'] ?? ''), 'ocid1.user.')) {
            throw new OciException('OCI user OCID is invalid', 422, 'config');
        }
        if (! preg_match(self::FINGERPRINT, (string) ($config['fingerprint'] ?? ''))) {
            throw new OciException('OCI fingerprint is invalid', 422, 'config');
        }
    }

    private function loadKey(#[SensitiveParameter] string $content, #[SensitiveParameter] ?string $passphrase): OpenSSLAsymmetricKey
    {
        $key = $passphrase !== null && $passphrase !== ''
            ? openssl_pkey_get_private($content, $passphrase)
            : openssl_pkey_get_private($content);

        if ($key === false) {
            throw new OciException('OCI private key or passphrase is invalid', 401, 'config');
        }
        if ((openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new OciException('OCI private key must be an RSA key', 422, 'config');
        }

        return $key;
    }
}
