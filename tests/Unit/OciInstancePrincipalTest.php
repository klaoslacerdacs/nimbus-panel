<?php

use App\Services\Oci\OciApiClient;
use App\Services\Oci\OciException;
use App\Services\Oci\OciInstancePrincipal;
use App\Services\Oci\OciRequestSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const IP_TENANCY = 'ocid1.tenancy.oc1..tenant';

/**
 * @return array{cert: string, key: string, public: string}
 */
function instanceIdentity(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'ocid1.instance.oc1..vm', 'organizationalUnitName' => 'opc-tenant:'.IP_TENANCY], $key);
    openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $cert);
    openssl_pkey_export($key, $keyPem);

    return ['cert' => $cert, 'key' => $keyPem, 'public' => openssl_pkey_get_details($key)['key']];
}

function securityToken(int $exp): string
{
    $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $part(['alg' => 'RS256']).'.'.$part(['exp' => $exp, 'sub' => 'ocid1.instance.oc1..vm']).'.sig';
}

function fakeOciMetadata(array $identity, string $token, array $extra = []): void
{
    Http::fake(array_merge([
        '169.254.169.254/opc/v2/identity/cert.pem' => Http::response($identity['cert']),
        '169.254.169.254/opc/v2/identity/key.pem' => Http::response($identity['key']),
        '169.254.169.254/opc/v2/identity/intermediate.pem' => Http::response($identity['cert']),
        '169.254.169.254/opc/v2/instance/canonicalRegionName' => Http::response('sa-saopaulo-1'),
        'auth.sa-saopaulo-1.oraclecloud.com/v1/x509' => Http::response(['token' => $token]),
    ], $extra));
}

beforeEach(fn () => OciInstancePrincipal::forget());

it('federates the instance certificate into a security token signed with the session key', function () {
    $identity = instanceIdentity();
    $token = securityToken(time() + 1200);
    fakeOciMetadata($identity, $token);

    $headers = app(OciRequestSigner::class)->signHeaders(['auth_mode' => 'instance_principal', 'region' => 'us-ashburn-1'], 'GET', 'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances', null);

    expect($headers['Authorization'])->toContain('keyId="ST$'.$token.'"');

    Http::assertSent(function (Request $request) use ($identity) {
        if (! str_contains($request->url(), 'auth.sa-saopaulo-1.oraclecloud.com/v1/x509')) {
            return str_contains($request->url(), '169.254.169.254') && $request->header('Authorization')[0] === 'Bearer Oracle';
        }

        $fingerprint = implode(':', str_split(strtoupper(openssl_x509_fingerprint($identity['cert'], 'sha256')), 2));
        preg_match('/keyId="([^"]+)".*signature="([^"]+)"/', $request->header('Authorization')[0], $m);
        $signingString = (new OciRequestSigner)->buildSigningString($request->header('Date')[0], 'POST', $request->url(), $request->body());

        return $m[1] === IP_TENANCY.'/fed-x509-sha256/'.$fingerprint
            && openssl_verify($signingString, base64_decode($m[2]), $identity['public'], OPENSSL_ALGO_SHA256) === 1
            && $request['fingerprintAlgorithm'] === 'SHA256'
            && ! str_contains($request['certificate'], 'BEGIN')
            && ! str_contains($request['publicKey'], 'BEGIN');
    });
});

it('reads the tenancy from the identity certificate', function () {
    expect(OciInstancePrincipal::tenancyFromCertificate(instanceIdentity()['cert']))->toBe(IP_TENANCY);
});

it('reuses the token until it nears expiry, then re-federates', function () {
    fakeOciMetadata(instanceIdentity(), '', ['auth.sa-saopaulo-1.oraclecloud.com/v1/x509' => Http::sequence()
        ->push(['token' => $long = securityToken(time() + 1200)])
        ->push(['token' => $short = securityToken(time() + 60)])
        ->push(['token' => $next = securityToken(time() + 1200)]),
    ]);
    $principal = app(OciInstancePrincipal::class);

    expect($principal->credentials()[0])->toBe('ST$'.$long)
        ->and($principal->credentials()[0])->toBe('ST$'.$long);

    OciInstancePrincipal::forget();
    expect($principal->credentials()[0])->toBe('ST$'.$short)
        ->and($principal->credentials()[0])->toBe('ST$'.$next); // inside the refresh margin: never reused
});

it('fails clearly when not running on an OCI instance', function () {
    Http::fake(['169.254.169.254/*' => Http::response('', 404)]);

    app(OciInstancePrincipal::class)->credentials();
})->throws(OciException::class, 'requires Nimbus Panel to run on an OCI instance');

it('fails when the auth service rejects the certificate', function () {
    fakeOciMetadata(instanceIdentity(), '', ['auth.sa-saopaulo-1.oraclecloud.com/v1/x509' => Http::response(['code' => 'NotAuthenticated'], 401)]);

    app(OciInstancePrincipal::class)->credentials();
})->throws(OciException::class, 'federation failed');

it('re-federates once when OCI rejects a cached token', function () {
    fakeOciMetadata(instanceIdentity(), securityToken(time() + 1200), [
        'iaas.us-ashburn-1.oraclecloud.com/*' => Http::sequence()->push(['code' => 'NotAuthenticated'], 401)->push(['id' => 'ok'], 200),
    ]);

    $result = app(OciApiClient::class)->get(['auth_mode' => 'instance_principal', 'region' => 'us-ashburn-1'], 'iaas', 'us-ashburn-1', '/20160918/instances/x');

    expect($result)->toBe(['id' => 'ok']);
    Http::assertSentCount(12); // 2 federations (5 each) + 2 API calls
});
