<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\OciStack;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListOciStacks extends Tool
{
    protected string $name = 'list_oci_stacks';

    protected string $description = 'List the OCI Resource Manager (Terraform) stacks that provisioned team servers: status, region, linked server and the last plan/apply summary. Use it to explain a failed or stuck OCI server provisioning.';

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

        $status = $request->get('status');
        $stacks = OciStack::query()
            ->where('team_id', $teamId)
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->with('server:id,uuid,name')
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (OciStack $stack): array => [
                'uuid' => $stack->uuid,
                'name' => $stack->name,
                'status' => $stack->status,
                'is_active' => $stack->isActive(),
                'region' => $stack->region,
                'server' => $stack->server ? ['uuid' => $stack->server->uuid, 'name' => $stack->server->name] : null,
                // Terraform job logs: redacted and truncated like deploy log summaries.
                'last_summary' => $stack->last_plan_summary ? str($this->redactLogText($stack->last_plan_summary))->limit(2000)->toString() : null,
                'updated_at' => $stack->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond($stacks));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional filter: pending | planning | applying | provisioned | failed | destroying | destroy_failed | destroyed.'),
        ];
    }
}
