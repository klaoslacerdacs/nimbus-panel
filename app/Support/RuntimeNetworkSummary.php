<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Database\Eloquent\Model;

/**
 * Nimbus: what the Logs page says about each container's network: the ports its image exposes and Docker
 * publishes (from `docker ps`), and the domain → container port routes the proxy uses, with a warning when
 * a route points at a port the container does not expose.
 */
class RuntimeNetworkSummary
{
    /**
     * @param  list<array<string, mixed>>  $containers  `docker ps --format '{{json .}}'` rows
     * @return list<array{container: string, exposed: list<int>, published: list<string>, routes: list<array{url: string, port: ?int}>, warnings: list<string>}>
     */
    public static function build(Model $resource, array $containers): array
    {
        return collect($containers)
            ->filter(fn ($container): bool => is_array($container) && is_string($container['Names'] ?? null))
            ->map(function (array $container) use ($resource): array {
                $ports = self::parsePorts((string) ($container['Ports'] ?? ''));
                $routes = self::routesFor($resource, $container);
                $unexposed = collect($routes)->pluck('port')->filter()->unique()
                    ->reject(fn (int $port): bool => $ports['exposed'] === [] || in_array($port, $ports['exposed'], true));

                return [
                    'container' => $container['Names'],
                    'exposed' => $ports['exposed'],
                    'published' => $ports['published'],
                    'routes' => $routes,
                    'warnings' => $unexposed->isEmpty() ? [] : ['The image declares (EXPOSE) only '.implode(', ', $ports['exposed']).', but the proxy routes to '.$unexposed->implode(', ').'. If the site does not load, check the port of the domain.'],
                ];
            })
            // A multi-server application repeats the same container on every server; one row says it all.
            ->unique('container')->values()->all();
    }

    /**
     * "0.0.0.0:3000->3000/tcp, :::3000->3000/tcp, 8080/tcp" → exposed [3000, 8080], published ["0.0.0.0:3000->3000/tcp"].
     *
     * @return array{exposed: list<int>, published: list<string>}
     */
    public static function parsePorts(string $ports): array
    {
        $entries = collect(explode(',', $ports))->map(fn (string $value): string => trim($value))->filter();

        return [
            // "3000-3005/tcp" is a range.
            'exposed' => $entries->flatMap(function (string $entry): array {
                [$from, $to] = array_pad(explode('-', str($entry)->afterLast('->')->before('/')->value(), 2), 2, null);

                return (int) $from > 0 ? range((int) $from, max((int) $from, (int) ($to ?? $from))) : [];
            })->unique()->sort()->values()->all(),
            // IPv6 duplicates (":::3000->3000/tcp", "[::]:3000->3000/tcp") say nothing the IPv4 line does not.
            'published' => $entries->filter(fn (string $entry): bool => str_contains($entry, '->') && ! preg_match('/^(:::|\[::\]:)/', $entry))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $container
     * @return list<array{url: string, port: ?int}>
     */
    private static function routesFor(Model $resource, array $container): array
    {
        if ($resource instanceof Service) {
            $application = $resource->applications->first(fn (ServiceApplication $app): bool => "{$app->name}-{$resource->uuid}" === $container['Names']);

            return $application ? self::routes($application->fqdn, $application->domain_port_overrides, $application->getRequiredPort()) : [];
        }
        if ($resource instanceof Application && $resource->build_pack === 'dockercompose') {
            $service = (string) format_docker_labels_to_json(filled($container['Labels'] ?? null) ? $container['Labels'] : [])->get('com.docker.compose.service');
            $domains = (string) data_get(json_decode((string) $resource->docker_compose_domains, true), "{$service}.domain");

            return self::routes($domains, $resource->domain_port_overrides, dockerComposeServicePort($resource->docker_compose_raw, $service));
        }
        if ($resource instanceof Application) {
            return self::routes($resource->fqdn, $resource->domain_port_overrides, $resource->main_port()[0] ?? null);
        }

        return [];
    }

    /**
     * @param  array<string, int|string>|null  $overrides
     * @return list<array{url: string, port: ?int}>
     */
    private static function routes(?string $fqdn, ?array $overrides, mixed $defaultPort): array
    {
        return collect(explode(',', (string) $fqdn))->map(fn (string $value): string => trim($value))->filter()
            ->map(function (string $url) use ($overrides, $defaultPort): array {
                // Same precedence as the Traefik labels: port in the URL, then the per-domain override, then the default.
                $urlPort = DomainUrlParts::split($url)['port'] ?? '';
                $port = $urlPort !== '' ? $urlPort : ($overrides[DomainPortOverrides::withoutPort($url)] ?? $defaultPort);

                return ['url' => DomainPortOverrides::withoutPort($url), 'port' => is_numeric($port) && (int) $port > 0 ? (int) $port : null];
            })
            ->values()->all();
    }
}
