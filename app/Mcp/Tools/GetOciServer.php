<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Server;
use App\Services\OciClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetOciServer extends Tool
{
    protected string $name = 'get_oci_server';

    protected string $description = 'Get the Oracle Cloud view of a team server by UUID: instance OCID, region, live lifecycle state and shape from OCI, current CPU/memory from OCI Monitoring, and its provisioning stack.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $server = Server::whereTeamId($teamId)->where('uuid', $uuid)->with(['ociConnection', 'ociStack'])->first();
        if (! $server) {
            return $this->mcpError($request, "Server [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }
        if (! $server->ociConnection || ! $server->oci_instance_id) {
            return $this->mcpError($request, "Server [{$uuid}] is not an Oracle Cloud instance.", ['resource_uuid' => $uuid]);
        }

        $config = $server->ociConnection->toApiConfig($server->oci_region);
        $oci = app(OciClient::class);
        $instance = $oci->getInstanceStatus($config, $server->oci_instance_id, (string) $server->oci_region);
        $metrics = $server->oci_compartment_id
            ? $oci->getInstanceMetrics($config, $server->oci_instance_id, $server->oci_compartment_id, (string) $server->oci_region)
            : ['error' => true, 'message' => 'No compartment recorded for this server.'];

        $data = [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'ip' => $server->ip,
            'oci_connection' => $server->ociConnection->name,
            'region' => $server->oci_region,
            'instance_ocid' => $server->oci_instance_id,
            'compartment_ocid' => $server->oci_compartment_id,
            'metrics_source' => $server->oci_metrics_source,
            'instance' => empty($instance['error'])
                ? array_intersect_key($instance, array_flip(['display_name', 'lifecycle_state', 'shape', 'availability_domain', 'time_created']))
                : ['error' => $instance['message'] ?? 'OCI request failed'],
            'metrics' => empty($metrics['error']) ? $metrics : ['error' => $metrics['message'] ?? 'OCI request failed'],
            'stack' => $server->ociStack ? [
                'uuid' => $server->ociStack->uuid,
                'status' => $server->ociStack->status,
            ] : null,
        ];

        $actions = [
            ...$this->actionsForServer($uuid),
            ['tool' => 'oci_instance_action', 'args' => ['uuid' => $uuid, 'action' => 'reboot', 'confirm' => true], 'hint' => 'Start / stop / reboot the VM (deploy ability)'],
        ];

        return $this->mcpSuccess($request, $this->respond($data, $actions), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Server UUID.')->required(),
        ];
    }
}
