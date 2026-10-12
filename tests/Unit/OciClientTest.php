<?php

namespace Tests\Unit;

use App\Services\OciClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OciClientTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

        $this->config = [
            'auth_mode' => 'api_key',
            'tenancy_id' => 'ocid1.tenancy.oc1..aaaa',
            'user_id' => 'ocid1.user.oc1..bbbb',
            'fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
            'key_content' => $pem,
            'passphrase' => null,
            'region' => 'us-ashburn-1',
        ];
    }

    public function test_list_regions_returns_named_entries_with_saopaulo(): void
    {
        $regions = (new OciClient)->listRegions();

        $names = array_column($regions, 'name');
        $this->assertContains('sa-saopaulo-1', $names);
        $this->assertArrayHasKey('name', $regions[0]);
    }

    public function test_validate_connection_success(): void
    {
        Http::fake(['https://identity.*' => Http::response(['id' => 'ocid1.tenancy.oc1..aaaa'])]);

        $result = (new OciClient)->validateConnection($this->config);

        $this->assertTrue($result['ok']);
        $this->assertSame('ocid1.tenancy.oc1..aaaa', $result['tenancy_ocid']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_list_instances_maps_oci_fields(): void
    {
        Http::fake(['https://iaas.*/instances*' => Http::response([
            ['id' => 'ocid1.instance.oc1..x', 'displayName' => 'web-1', 'lifecycleState' => 'RUNNING', 'shape' => 'VM.Standard.A1.Flex'],
        ])]);

        $result = (new OciClient)->listInstances($this->config, 'ocid1.compartment.oc1..c', 'us-ashburn-1');

        $this->assertCount(1, $result);
        $this->assertSame('web-1', $result[0]['display_name']);
        $this->assertSame('RUNNING', $result[0]['lifecycle_state']);
        $this->assertSame('us-ashburn-1', $result[0]['region']);
    }

    public function test_run_stack_job_unwraps_job_id(): void
    {
        Http::fake(['https://resourcemanager.*/jobs' => Http::response(['id' => 'ocid1.ormjob.oc1..j'])]);

        $result = (new OciClient)->runStackJob($this->config, 'ocid1.ormstack.oc1..s', 'PLAN');

        $this->assertSame('ocid1.ormjob.oc1..j', $result['job_id']);
        $this->assertSame('plan', $result['operation']);
    }

    public function test_destroy_without_confirmation_is_blocked(): void
    {
        Http::fake();

        $result = (new OciClient)->runStackJob($this->config, 'ocid1.ormstack.oc1..s', 'DESTROY');

        $this->assertTrue($result['error']);
        Http::assertNothingSent();
    }

    public function test_service_error_is_normalized(): void
    {
        Http::fake(['https://iaas.*' => Http::response(['code' => 'NotAuthenticated', 'message' => 'Not authenticated'], 401)]);

        $result = (new OciClient)->listInstances($this->config, 'c', 'us-ashburn-1');

        $this->assertTrue($result['error']);
        $this->assertSame('Not authenticated', $result['message']);
        $this->assertSame(401, $result['code']);
        $this->assertSame('NotAuthenticated', $result['details']);
    }

    public function test_signature_header_is_sent(): void
    {
        Http::fake(['https://iaas.*' => Http::response([])]);

        (new OciClient)->listInstances($this->config, 'c', 'us-ashburn-1');

        Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0] ?? '', 'Signature version="1"'));
    }
}
