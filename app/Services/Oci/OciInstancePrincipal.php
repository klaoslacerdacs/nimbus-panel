<?php

namespace App\Services\Oci;

use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;

/**
 * Instance Principal auth: the host VM's identity certificate (from the
 * instance metadata service) is exchanged at the regional auth service for a
 * short-lived security token bound to an in-memory session key. Same flow as
 * the official SDK's InstancePrincipalsSecurityTokenSigner.
 */
class OciInstancePrincipal
{
    private const string METADATA = 'http://169.254.169.254/opc/v2';

    private const int REFRESH_MARGIN = 300;

    /**
     * ponytail: per-process cache — every FPM/Horizon worker federates once per token lifetime (~20 min); move to a shared lock if auth throttles.
     *
     * @var array{token: string, key: OpenSSLAsymmetricKey, expires: int, tenancy: string}|null
     */
    private static ?array $session = null;

    public function __construct(private readonly OciRequestSigner $signer) {}

    /**
     * @return array{0: string, 1: OpenSSLAsymmetricKey} keyId and signing key for OCI requests.
     */
    public function credentials(): array
    {
        $session = $this->session();

        return ['ST$'.$session['token'], $session['key']];
    }

    public function tenancyId(): string
    {
        return $this->session()['tenancy'];
    }

    public static function forget(): void
    {
        self::$session = null;
    }

    /**
     * @return array{token: string, key: OpenSSLAsymmetricKey, expires: int, tenancy: string}
     */
    private function session(): array
    {
        if (self::$session !== null && time() < self::$session['expires'] - self::REFRESH_MARGIN) {
            return self::$session;
        }

        return self::$session = $this->federate();
    }

    /**
     * @return array{token: string, key: OpenSSLAsymmetricKey, expires: int, tenancy: string}
     */
    private function federate(): array
    {
        $leafCertificate = $this->metadata('identity/cert.pem');
        $leafKey = openssl_pkey_get_private($this->metadata('identity/key.pem'));
        $intermediate = $this->metadata('identity/intermediate.pem');
        $region = trim($this->metadata('instance/canonicalRegionName'));

        if ($leafKey === false || ! preg_match('/^[a-z0-9-]+$/', $region)) {
            throw new OciException('Instance metadata returned an invalid identity', 502, 'instance_principal');
        }

        $tenancy = self::tenancyFromCertificate($leafCertificate);
        $fingerprint = openssl_x509_fingerprint($leafCertificate, 'sha256');
        if ($fingerprint === false) {
            throw new OciException('Instance identity certificate is invalid', 502, 'instance_principal');
        }

        $sessionKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $body = json_encode([
            'certificate' => self::stripPem($leafCertificate),
            'publicKey' => self::stripPem(openssl_pkey_get_details($sessionKey)['key']),
            'intermediateCertificates' => [self::stripPem($intermediate)],
            'purpose' => 'DEFAULT',
            'fingerprintAlgorithm' => 'SHA256',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $url = "https://auth.{$region}.oraclecloud.com/v1/x509";
        $keyId = $tenancy.'/fed-x509-sha256/'.implode(':', str_split(strtoupper($fingerprint), 2));
        $headers = $this->signer->sign($keyId, $leafKey, 'POST', $url, $body);

        $response = Http::timeout((int) config('oci.timeout', 30))
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($url);

        $token = $response->successful() ? $response->json('token') : null;
        if (! is_string($token) || $token === '') {
            throw new OciException('Instance Principal federation failed — check the dynamic group and IAM policies', $response->status() ?: 502, 'instance_principal');
        }

        return ['token' => $token, 'key' => $sessionKey, 'expires' => self::tokenExpiry($token), 'tenancy' => $tenancy];
    }

    private function metadata(string $path): string
    {
        try {
            $response = Http::timeout(5)->withHeaders(['Authorization' => 'Bearer Oracle'])->get(self::METADATA.'/'.$path);
        } catch (\Throwable) {
            $response = null;
        }

        if (! $response?->successful()) {
            throw new OciException('Instance Principal requires Nimbus Panel to run on an OCI instance (metadata service unreachable)', 424, 'instance_principal');
        }

        return $response->body();
    }

    public static function tenancyFromCertificate(string $certificate): string
    {
        $subject = openssl_x509_parse($certificate)['subject'] ?? [];

        foreach (['OU' => 'opc-tenant:', 'O' => 'opc-identity:'] as $field => $prefix) {
            foreach ((array) ($subject[$field] ?? []) as $value) {
                if (str_starts_with($value, $prefix)) {
                    return substr($value, strlen($prefix));
                }
            }
        }

        throw new OciException('Tenancy not found in instance identity certificate', 502, 'instance_principal');
    }

    private static function stripPem(string $pem): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }

    private static function tokenExpiry(string $jwt): int
    {
        $payload = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);

        return (int) ($payload['exp'] ?? time() + 600);
    }
}
