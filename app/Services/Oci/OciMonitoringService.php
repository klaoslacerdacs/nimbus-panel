<?php

namespace App\Services\Oci;

/**
 * Instance metrics via the Monitoring SummarizeMetricsData action. CPU tries
 * oci_computeagent first (Ampere shapes) then oci_compute; memory/network need
 * the Oracle Cloud Agent and return null when it is not reporting.
 */
class OciMonitoringService
{
    private const string PATH = '/20180401/metrics/actions/summarizeMetricsData';

    public function __construct(private readonly OciApiClient $api) {}

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
     * CPU/memory history in the Sentinel chart format: list of [epoch milliseconds, percent].
     *
     * @param  array<string, mixed>  $config
     * @return array{cpu: list<array{0: int, 1: float}>, memory: list<array{0: int, 1: float}>}
     */
    public function getInstanceMetricSeries(array $config, string $instanceId, string $compartmentId, string $region, int $minutes): array
    {
        $end = gmdate('Y-m-d\TH:i:s.000\Z');
        $start = gmdate('Y-m-d\TH:i:s.000\Z', time() - $minutes * 60);
        // OCI caps a query at ~100k points; coarser buckets for long windows keep charts light.
        $resolution = match (true) {
            $minutes <= 360 => '1m',
            $minutes <= 2880 => '5m',
            default => '1h',
        };
        $dim = "resourceId = \"{$instanceId}\"";

        $cpu = $this->series($config, $compartmentId, $region, 'oci_computeagent', "CpuUtilization[{$resolution}]{{$dim}}.mean()", $start, $end, $resolution)
            ?: $this->series($config, $compartmentId, $region, 'oci_compute', "CpuUtilization[{$resolution}]{{$dim}}.mean()", $start, $end, $resolution);

        return [
            'cpu' => $cpu,
            'memory' => $this->series($config, $compartmentId, $region, 'oci_computeagent', "MemoryUtilization[{$resolution}]{{$dim}}.mean()", $start, $end, $resolution),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{0: int, 1: float}>
     */
    private function series(array $config, string $compartmentId, string $region, string $namespace, string $mql, string $start, string $end, string $resolution): array
    {
        try {
            $resp = $this->api->post($config, 'telemetry', $region, self::PATH, [
                'namespace' => $namespace,
                'query' => $mql,
                'startTime' => $start,
                'endTime' => $end,
                'resolution' => $resolution,
            ], ['compartmentId' => $compartmentId]);
        } catch (OciException) {
            return [];
        }

        return array_values(array_map(
            fn (array $p): array => [strtotime($p['timestamp']) * 1000, round((float) $p['value'], 2)],
            array_filter($resp[0]['aggregatedDatapoints'] ?? [], fn ($p): bool => isset($p['timestamp'], $p['value'])),
        ));
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
