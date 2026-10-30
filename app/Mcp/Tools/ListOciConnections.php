<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\OciConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListOciConnections extends Tool
{
    protected string $name = 'list_oci_connections';

    protected string $description = 'List the Oracle Cloud (OCI) connections of the team: name, region, auth method and tenancy. Never returns keys or passphrases.';

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

        $connections = OciConnection::ownedByTeam($teamId)
            ->orderBy('name')
            ->get()
            ->map(fn (OciConnection $connection): array => [
                'uuid' => $connection->uuid,
                'name' => $connection->name,
                'region' => $connection->region,
                'authentication_method' => $connection->authentication_method->value,
                'tenancy_ocid' => $connection->tenancy_ocid,
                'default_compartment_ocid' => $connection->compartment_ocid,
            ])
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond($connections));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
