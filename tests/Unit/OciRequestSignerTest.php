<?php

namespace Tests\Unit;

use App\Services\Oci\OciException;
use App\Services\Oci\OciRequestSigner;
use Tests\TestCase;

class OciRequestSignerTest extends TestCase
{
    private function validConfig(string $pem): array
    {
        return [
            'auth_mode' => 'api_key',
            'tenancy_id' => 'ocid1.tenancy.oc1..aaaa',
            'user_id' => 'ocid1.user.oc1..bbbb',
            'fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'key_content' => $pem,
            'passphrase' => null,
            'region' => 'us-ashburn-1',
        ];
    }

    private function generateKey(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);

        return $pem;
    }

    public function test_signing_string_for_get_is_byte_exact(): void
    {
        $signer = new OciRequestSigner;

        $string = $signer->buildSigningString(
            'Thu, 05 Jan 2014 21:31:40 GMT',
            'GET',
            'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances?compartmentId=ocid1.compartment.oc1..aaa',
            null,
        );

        $this->assertSame(
            "date: Thu, 05 Jan 2014 21:31:40 GMT\n".
            "(request-target): get /20160918/instances?compartmentId=ocid1.compartment.oc1..aaa\n".
            'host: iaas.us-ashburn-1.oraclecloud.com',
            $string,
        );
    }

    public function test_signing_string_for_post_includes_body_headers(): void
    {
        $signer = new OciRequestSigner;
        $body = '{"stackId":"ocid1.ormstack.oc1..zzz"}';

        $string = $signer->buildSigningString(
            'Thu, 05 Jan 2014 21:31:40 GMT',
            'POST',
            'https://resourcemanager.us-ashburn-1.oraclecloud.com/20180917/jobs',
            $body,
        );

        $this->assertSame(
            "date: Thu, 05 Jan 2014 21:31:40 GMT\n".
            "(request-target): post /20180917/jobs\n".
            "host: resourcemanager.us-ashburn-1.oraclecloud.com\n".
            'content-length: '.strlen($body)."\n".
            "content-type: application/json\n".
            'x-content-sha256: '.base64_encode(hash('sha256', $body, true)),
            $string,
        );
    }

    public function test_signature_verifies_against_public_key(): void
    {
        $pem = $this->generateKey();
        $signer = new OciRequestSigner;
        $url = 'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances?compartmentId=c';

        $headers = $signer->signHeaders($this->validConfig($pem), 'GET', $url, null);

        $this->assertStringStartsWith('Signature version="1"', $headers['Authorization']);
        preg_match('/signature="([^"]+)"/', $headers['Authorization'], $m);
        $signature = base64_decode($m[1]);

        $signingString = $signer->buildSigningString($headers['Date'], 'GET', $url, null);
        $public = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];

        $this->assertSame(1, openssl_verify($signingString, $signature, $public, OPENSSL_ALGO_SHA256));
    }

    public function test_invalid_key_throws(): void
    {
        $this->expectException(OciException::class);

        $config = $this->validConfig('not-a-key');
        (new OciRequestSigner)->signHeaders($config, 'GET', 'https://iaas.us-ashburn-1.oraclecloud.com/x', null);
    }

    public function test_signing_string_for_delete_has_no_body_headers(): void
    {
        $signer = new OciRequestSigner;

        $string = $signer->buildSigningString(
            'Mon, 01 Jan 2024 00:00:00 GMT',
            'DELETE',
            'https://iaas.us-ashburn-1.oraclecloud.com/20160918/instances/ocid1.instance.oc1..abc',
            null,
        );

        $this->assertStringContainsString('date:', $string);
        $this->assertStringContainsString('(request-target): delete ', $string);
        $this->assertStringContainsString('host: iaas.us-ashburn-1.oraclecloud.com', $string);
        $this->assertStringNotContainsString('content-length', $string);
        $this->assertStringNotContainsString('x-content-sha256', $string);
    }
}
