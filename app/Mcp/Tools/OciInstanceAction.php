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

class OciInstanceAction extends Tool
{
    protected string $name = 'oci_instance_action';

    protected string $description = 'Start, stop or reboot the Oracle Cloud VM behind a team server. Requires deploy ability. stop and reboot require confirm=true. Graceful (SOFTSTOP/SOFTRESET); never creates or destroys infrastructure.';

    /** @var array<string, string> */
    private const ACTIONS = ['start' => 'START', 'stop' => 'SOFTSTOP', 'reboot' => 'SOFTRESET'];

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'deploy', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('uuid');
        $action = $request->get('action');
        $confirm = filter_var($request->get('confirm'), FILTER_VALIDATE_BOOLEAN);

        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }
        if (! is_string($action) || ! isset(self::ACTIONS[$action])) {
            return $this->mcpError($request, 'action must be start, stop, or reboot.');
        }
        if ($action !== 'start' && ! $confirm) {
            return $this->mcpError($request, "{$action} requires confirm=true to prevent accidental downtime.");
        }

        $server = Server::whereTeamId($teamId)->where('uuid', $uuid)->with('ociConnection')->first();
        if (! $server) {
            return $this->mcpError($request, "Server [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }
        if (! $server->ociConnection || ! $server->oci_instance_id) {
            return $this->mcpError($request, "Server [{$uuid}] is not an Oracle Cloud instance.", ['resource_uuid' => $uuid]);
        }

        $result = app(OciClient::class)->instanceAction(
            $server->ociConnection->toApiConfig($server->oci_region),
            $server->oci_instance_id,
            (string) $server->oci_region,
            self::ACTIONS[$action],
        );

        auditLog('mcp.oci_instance_action', [
            'team_id' => $teamId,
            'server_uuid' => $uuid,
            'action' => $action,
            'outcome' => empty($result['error']) ? 'success' : 'failed',
        ]);

        if (! empty($result['error'])) {
            return $this->mcpError($request, $result['message'] ?? 'OCI rejected the instance action.', ['resource_uuid' => $uuid, 'action' => $action]);
        }

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'uuid' => $uuid,
            'action' => $action,
            'lifecycle_state' => $result['lifecycle_state'] ?? null,
            'next_tools' => [
                ['tool' => 'get_oci_server', 'args' => ['uuid' => $uuid], 'hint' => 'Poll the lifecycle state'],
            ],
        ]), ['resource_uuid' => $uuid, 'action' => $action]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Server UUID.')->required(),
            'action' => $schema->string()->description('start | stop | reboot')->required(),
            'confirm' => $schema->boolean()->description('Required true for stop and reboot.'),
        ];
    }
}
