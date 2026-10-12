<?php

namespace App\Services\Oci;

/**
 * Instance metrics via the Monitoring SummarizeMetricsData action. CPU comes
 * from the oci_compute namespace (no agent); memory/network from
 * oci_computeagent (agent required) — those return null when unavailable,
 * matching the Python bridge.
 */
class OciMonitoringService
{
    private const PATH = '/20180401/metrics/actions/summarizeMetricsData';

    public function __construct(private OciApiClient $api) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array{cpu_percent: float|null, memory_percent: float|null, net_bytes_in: int|null, net_bytes_out: int|null}
     */
    public function getInstanceMetrics(array $config, string $instanceId, string $compartmentId, string $region): array
    {
        $end = gmdate('Y-m-d\TH:i:s.000\Z');
        $start = gmdate('Y-m-d\TH:i:s.000\Z', time() - 600);
        $dim = "resourceId = \"{$instanceId}\"";

        // ponytail: 4 sequential POSTs — parallelize with Guzzle concurrent promises when latency matters
        // Ampere/ARM shapes don't report CpuUtilization in oci_compute (hypervisor); try agent first
        $cpu = $this->query($config, $compartmentId, $region, 'oci_computeagent', "CpuUtilization[5m]{{$dim}}.mean()", $start, $end)
            ?? $this->query($config, $compartmentId, $region, 'oci_compute', "CpuUtilization[5m]{{$dim}}.mean()", $start, $end);
        $memory = $this->query($config, $compartmentId, $region, 'oci_computeagent', "MemoryUtilization[5m]{{$dim}}.mean()", $start, $end);
        $netIn = $this->query($config, $compartmentId, $region, 'oci_computeagent', "NetworksBytesIn[5m]{{$dim}}.mean()", $start, $end);
        $netOut = $this->query($config, $compartmentId, $region, 'oci_computeagent', "NetworksBytesOut[5m]{{$dim}}.mean()", $start, $end);

        return [
            'cpu_percent' => $cpu !== null ? round($cpu, 2) : null,
            'memory_percent' => $memory !== null ? round($memory, 2) : null,
            'net_bytes_in' => $netIn !== null ? (int) $netIn : null,
            'net_bytes_out' => $netOut !== null ? (int) $netOut : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function query(array $config, string $compartmentId, string $region, string $namespace, string $mql, string $start, string $end): ?float
    {
        try {
            $resp = $this->api->post($config, 'telemetry', $region, self::PATH, [
                'namespace' => $namespace,
                'query' => $mql,
                'startTime' => $start,
                'endTime' => $end,
                'resolution' => '5m',
            ], ['compartmentId' => $compartmentId]);

            $points = $resp[0]['aggregatedDatapoints'] ?? [];
            if ($points === []) {
                return null;
            }
            $last = end($points);

            return isset($last['value']) ? (float) $last['value'] : null;
        } catch (OciException) {
            return null;
        }
    }
}
