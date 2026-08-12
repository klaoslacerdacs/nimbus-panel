<?php

namespace Tests\Unit;

use App\Services\OciBridgeClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OciBridgeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('oci.bridge_url', 'http://test');
        Config::set('oci.bridge_timeout', 10);
        Config::set('oci.bridge_retry_max', 2);
        Config::set('oci.bridge_auth_token', 'token');
    }

    public function test_validate_connection_success(): void
    {
        Http::fake([
            'http://test/v1/connections/validate' => Http::response(['data' => ['valid' => true]]),
        ]);

        $client = new OciBridgeClient;
        $result = $client->validateConnection(['tenancy_ocid' => 'ocid1']);

        $this->assertEquals(['valid' => true], $result);
    }

    public function test_list_regions_success(): void
    {
        Http::fake([
            'http://test/v1/regions' => Http::response(['data' => ['regions' => []]]),
        ]);

        $client = new OciBridgeClient;
        $result = $client->listRegions();

        $this->assertEquals(['regions' => []], $result);
    }

    public function test_list_compartments_success(): void
    {
        Http::fake([
            'http://test/v1/compartments?region=us-ashburn-1' => Http::response(['data' => ['compartments' => []]]),
        ]);

        $client = new OciBridgeClient;
        $result = $client->listCompartments('us-ashburn-1');

        $this->assertEquals(['compartments' => []], $result);
    }

    public function test_list_instances_success(): void
    {
        Http::fake([
            'http://test/v1/compute/instances?compartment_id=comp&region=us-ashburn-1' => Http::response(['data' => ['instances' => []]]),
        ]);

        $client = new OciBridgeClient;
        $result = $client->listInstances('comp', 'us-ashburn-1');

        $this->assertEquals(['instances' => []], $result);
    }

    public function test_error_normalization(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Bad request', 'code' => 400], 400),
        ]);

        $client = new OciBridgeClient;
        $result = $client->listRegions();

        $this->assertFalse($result['success']);
        $this->assertTrue($result['error']);
        $this->assertEquals('Bad request', $result['message']);
        $this->assertEquals(400, $result['code']);
    }

    public function test_auth_header_sent_when_token_set(): void
    {
        Http::fake([
            'http://test/v1/regions' => Http::response(['data' => ['regions' => []]]),
        ]);

        $client = new OciBridgeClient;
        $client->listRegions();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer token');
        });
    }

    public function test_no_auth_header_when_token_null(): void
    {
        Config::set('oci.bridge_auth_token', null);

        Http::fake([
            'http://test/v1/regions' => Http::response(['data' => ['regions' => []]]),
        ]);

        $client = new OciBridgeClient;
        $client->listRegions();

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('Authorization');
        });
    }
}
