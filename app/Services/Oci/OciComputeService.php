<?php

namespace App\Services\Oci;

/**
 * Compute + network + block volume + load balancer + autonomous DB inventory
 * and actions, mapped to snake_case arrays for the Livewire layer.
 */
class OciComputeService
{
    private const string IAAS = '/20160918';

    private const string LB = '/20170115';

    private const array IMAGE_OPERATING_SYSTEMS = ['Oracle Linux', 'Canonical Ubuntu', 'Rocky Linux'];

    private const array INSTANCE_ACTIONS = ['START', 'SOFTSTOP', 'SOFTRESET'];

    public function __construct(private readonly OciApiClient $api) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{id: string, name: string, description: string|null}>
     */
    public function listCompartments(array $config, string $region): array
    {
        $rows = $this->api->paginate($config, 'identity', $region, self::IAAS.'/compartments', [
            'compartmentId' => OciInstancePrincipal::resolveTenancy($config),
        ]);

        return array_map(fn (array $c): array => [
            'id' => $c['id'],
            'name' => $c['name'],
            'description' => $c['description'] ?? null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listInstances(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/instances', [
            'compartmentId' => $compartmentId,
        ]);

        return array_map(fn (array $i): array => [
            'id' => $i['id'],
            'display_name' => $i['displayName'] ?? null,
            'lifecycle_state' => $i['lifecycleState'] ?? null,
            'shape' => $i['shape'] ?? null,
            'region' => $region,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{name: string, id: string}>
     */
    public function listAvailabilityDomains(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->get($config, 'identity', $region, self::IAAS.'/availabilityDomains', [
            'compartmentId' => $compartmentId,
        ]);

        return array_map(fn (array $d): array => ['name' => $d['name'], 'id' => $d['id']], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listShapes(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/shapes', [
            'compartmentId' => $compartmentId,
        ]);

        return array_map(fn (array $s): array => [
            'name' => $s['shape'],
            'ocpus' => $s['ocpus'] ?? null,
            'memory_in_gbs' => $s['memoryInGBs'] ?? null,
            'is_flexible' => $s['isFlexible'] ?? null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listImages(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/images', [
            'compartmentId' => $compartmentId,
        ]);

        $images = [];
        foreach ($rows as $img) {
            if (in_array($img['operatingSystem'] ?? '', self::IMAGE_OPERATING_SYSTEMS, true)
                && ($img['lifecycleState'] ?? '') === 'AVAILABLE') {
                $images[] = [
                    'id' => $img['id'],
                    'display_name' => $img['displayName'] ?? null,
                    'operating_system' => $img['operatingSystem'],
                    'os_version' => $img['operatingSystemVersion'] ?? null,
                ];
            }
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listSubnets(array $config, string $vcnId, string $compartmentId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/subnets', [
            'compartmentId' => $compartmentId,
            'vcnId' => $vcnId,
        ]);

        return $this->mapSubnets($rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listVcns(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/vcns', [
            'compartmentId' => $compartmentId,
        ]);

        return array_map(fn (array $v): array => [
            'id' => $v['id'],
            'display_name' => $v['displayName'] ?? null,
            'cidr_blocks' => $v['cidrBlocks'] ?? null,
            'lifecycle_state' => $v['lifecycleState'] ?? null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listNsgRules(array $config, string $nsgId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS."/networkSecurityGroups/{$nsgId}/securityRules", [
            'direction' => 'INGRESS',
        ]);

        return array_map(function (array $r): array {
            $rule = [
                'id' => $r['id'],
                'protocol' => $r['protocol'],
                'source' => $r['source'] ?? null,
                'source_type' => $r['sourceType'] ?? null,
                'description' => $r['description'] ?? '',
            ];
            $range = $r['tcpOptions']['destinationPortRange'] ?? $r['udpOptions']['destinationPortRange'] ?? null;
            if ($range) {
                $rule['port_min'] = $range['min'] ?? null;
                $rule['port_max'] = $range['max'] ?? null;
            }

            return $rule;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array{id: string, description: string}>
     */
    public function addNsgRules(array $config, string $nsgId, array $rules, string $region): array
    {
        $resp = $this->api->post($config, 'iaas', $region, self::IAAS."/networkSecurityGroups/{$nsgId}/actions/addSecurityRules", [
            'securityRules' => array_map($this->buildSecurityRule(...), $rules),
        ]);

        return array_map(fn (array $r): array => [
            'id' => $r['id'],
            'description' => $r['description'] ?? '',
        ], $resp['securityRules'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $ruleIds
     * @return array{removed: int}
     */
    public function removeNsgRules(array $config, string $nsgId, array $ruleIds, string $region): array
    {
        $this->api->post($config, 'iaas', $region, self::IAAS."/networkSecurityGroups/{$nsgId}/actions/removeSecurityRules", [
            'securityRuleIds' => array_values($ruleIds),
        ]);

        return ['removed' => count($ruleIds)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function getInstanceStatus(array $config, string $instanceId, string $region): array
    {
        $i = $this->api->get($config, 'iaas', $region, self::IAAS."/instances/{$instanceId}");

        return [
            'id' => $i['id'],
            'display_name' => $i['displayName'] ?? null,
            'lifecycle_state' => $i['lifecycleState'] ?? null,
            'shape' => $i['shape'] ?? null,
            'availability_domain' => $i['availabilityDomain'] ?? null,
            'region' => $region,
            'time_created' => $i['timeCreated'] ?? null,
        ];
    }

    /**
     * Resolve the primary VNIC of an instance and return its public/private IPs.
     *
     * @param  array<string, mixed>  $config
     * @return array{public_ip: string|null, private_ip: string|null}
     */
    public function getInstancePrimaryVnic(array $config, string $compartmentId, string $instanceId, string $region): array
    {
        $attachments = $this->api->get($config, 'iaas', $region, self::IAAS.'/vnicAttachments', [
            'compartmentId' => $compartmentId,
            'instanceId' => $instanceId,
        ]);

        $vnicId = $attachments[0]['vnicId'] ?? null;
        if (! $vnicId) {
            return ['public_ip' => null, 'private_ip' => null];
        }

        $vnic = $this->api->get($config, 'iaas', $region, self::IAAS."/vnics/{$vnicId}");

        return ['public_ip' => $vnic['publicIp'] ?? null, 'private_ip' => $vnic['privateIp'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{lifecycle_state: string|null}
     */
    public function instanceAction(array $config, string $instanceId, string $region, string $action): array
    {
        $action = strtoupper($action);
        if (! in_array($action, self::INSTANCE_ACTIONS, true)) {
            throw new OciException('action must be START, SOFTSTOP, or SOFTRESET', 422, 'validation');
        }

        $resp = $this->api->post($config, 'iaas', $region, self::IAAS."/instances/{$instanceId}", null, [
            'action' => $action,
        ]);

        return ['lifecycle_state' => $resp['lifecycleState'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listVolumeAttachments(array $config, string $compartmentId, string $instanceId, string $region): array
    {
        $dataVols = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/volumeAttachments', [
            'compartmentId' => $compartmentId,
            'instanceId' => $instanceId,
            'limit' => 100,
        ]);

        $bootVols = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/bootVolumeAttachments', [
            'compartmentId' => $compartmentId,
            'instanceId' => $instanceId,
            'limit' => 100,
        ]);

        $sizeOf = function (string $path) use ($config, $region): ?int {
            try {
                $v = $this->api->get($config, 'iaas', $region, $path);

                return isset($v['sizeInGBs']) ? (int) $v['sizeInGBs'] : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $mapBoot = fn (array $a): array => [
            'id' => $a['id'],
            'volume_id' => $a['bootVolumeId'] ?? null,
            'display_name' => $a['displayName'] ?? 'Boot Volume',
            'lifecycle_state' => $a['lifecycleState'] ?? null,
            'attachment_type' => 'boot',
            'is_read_only' => false,
            'size_in_gbs' => isset($a['bootVolumeId']) ? $sizeOf(self::IAAS."/bootVolumes/{$a['bootVolumeId']}") : null,
        ];

        $mapData = fn (array $a): array => [
            'id' => $a['id'],
            'volume_id' => $a['volumeId'] ?? null,
            'display_name' => $a['displayName'] ?? null,
            'lifecycle_state' => $a['lifecycleState'] ?? null,
            'attachment_type' => $a['attachmentType'] ?? null,
            'is_read_only' => $a['isReadOnly'] ?? false,
            'size_in_gbs' => isset($a['volumeId']) ? $sizeOf(self::IAAS."/volumes/{$a['volumeId']}") : null,
        ];

        return array_merge(
            array_map($mapBoot, $bootVols),
            array_map($mapData, $dataVols),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function attachVolume(array $config, string $instanceId, string $volumeId, string $region, bool $readOnly = false): array
    {
        $a = $this->api->post($config, 'iaas', $region, self::IAAS.'/volumeAttachments', [
            'type' => 'paravirtualized',
            'instanceId' => $instanceId,
            'volumeId' => $volumeId,
            'isReadOnly' => $readOnly,
        ]);

        return [
            'id' => $a['id'],
            'volume_id' => $a['volumeId'] ?? null,
            'lifecycle_state' => $a['lifecycleState'] ?? null,
            'attachment_type' => $a['attachmentType'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{detached: true}
     */
    public function detachVolume(array $config, string $volumeAttachmentId, string $region): array
    {
        $this->api->delete($config, 'iaas', $region, self::IAAS."/volumeAttachments/{$volumeAttachmentId}");

        return ['detached' => true];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listLoadBalancers(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->get($config, 'loadbalancer', $region, self::LB.'/loadBalancers', [
            'compartmentId' => $compartmentId,
            'limit' => 100,
        ]);

        return array_map(fn (array $lb): array => [
            'id' => $lb['id'],
            'display_name' => $lb['displayName'] ?? null,
            'lifecycle_state' => $lb['lifecycleState'] ?? null,
            'shape_name' => $lb['shapeName'] ?? null,
            'ip_addresses' => array_column($lb['ipAddresses'] ?? [], 'ipAddress'),
            'backend_sets' => array_keys($lb['backendSets'] ?? []),
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listAutonomousDatabases(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->get($config, 'database', $region, self::IAAS.'/autonomousDatabases', [
            'compartmentId' => $compartmentId,
            'limit' => 100,
        ]);

        return array_map(fn (array $adb): array => [
            'id' => $adb['id'],
            'display_name' => $adb['displayName'] ?? null,
            'db_name' => $adb['dbName'] ?? null,
            'lifecycle_state' => $adb['lifecycleState'] ?? null,
            'db_workload' => $adb['dbWorkload'] ?? null,
            'cpu_core_count' => $adb['cpuCoreCount'] ?? null,
            'data_storage_size_in_tbs' => $adb['dataStorageSizeInTBs'] ?? null,
            'connection_strings' => $adb['connectionStrings']['allConnectionStrings'] ?? null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function createVolume(array $config, string $compartmentId, string $availabilityDomain, string $displayName, int $sizeInGBs, string $region): array
    {
        $v = $this->api->post($config, 'iaas', $region, self::IAAS.'/volumes', [
            'compartmentId' => $compartmentId,
            'availabilityDomain' => $availabilityDomain,
            'displayName' => $displayName,
            'sizeInGBs' => $sizeInGBs,
        ]);

        return [
            'id' => $v['id'],
            'display_name' => $v['displayName'] ?? $displayName,
            'lifecycle_state' => $v['lifecycleState'] ?? null,
            'size_in_gbs' => isset($v['sizeInGBs']) ? (int) $v['sizeInGBs'] : $sizeInGBs,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listVolumes(array $config, string $compartmentId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/volumes', [
            'compartmentId' => $compartmentId,
            'limit' => 100,
        ]);

        return array_map(fn (array $v): array => [
            'id' => $v['id'],
            'display_name' => $v['displayName'] ?? null,
            'lifecycle_state' => $v['lifecycleState'] ?? null,
            'size_in_gbs' => isset($v['sizeInGBs']) ? (int) $v['sizeInGBs'] : null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listRouteTables(array $config, string $compartmentId, string $vcnId, string $region): array
    {
        $rows = $this->api->paginate($config, 'iaas', $region, self::IAAS.'/routeTables', [
            'compartmentId' => $compartmentId,
            'vcnId' => $vcnId,
            'limit' => 100,
        ]);

        return array_map(fn (array $rt): array => [
            'id' => $rt['id'],
            'display_name' => $rt['displayName'] ?? null,
            'lifecycle_state' => $rt['lifecycleState'] ?? null,
            'route_rules' => array_map(fn (array $r): array => [
                'destination' => $r['destination'] ?? null,
                'destination_type' => $r['destinationType'] ?? null,
                'network_entity_id' => $r['networkEntityId'] ?? null,
            ], $rt['routeRules'] ?? []),
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    public function listBuckets(array $config, string $compartmentId, string $region): array
    {
        $ns = trim($this->api->rawGet($config, 'objectstorage', $region, '/n'), "\" \n");

        $rows = $this->api->paginate($config, 'objectstorage', $region, "/n/{$ns}/b", [
            'compartmentId' => $compartmentId,
            'limit' => 100,
        ]);

        return array_map(fn (array $b): array => [
            'name' => $b['name'],
            'namespace' => $ns,
            'storage_tier' => $b['storageTier'] ?? null,
            'approximate_count' => $b['approximateCount'] ?? null,
            'approximate_size' => $b['approximateSize'] ?? null,
            'time_created' => $b['timeCreated'] ?? null,
        ], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapSubnets(array $rows): array
    {
        return array_map(fn (array $s): array => [
            'id' => $s['id'],
            'display_name' => $s['displayName'] ?? null,
            'cidr_block' => $s['cidrBlock'] ?? null,
            'availability_domain' => $s['availabilityDomain'] ?? null,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function buildSecurityRule(array $r): array
    {
        $protocol = ['TCP' => '6', 'UDP' => '17', 'ICMP' => '1', 'ALL' => 'all'][strtoupper($r['protocol'] ?? 'TCP')] ?? '6';

        $rule = [
            'direction' => 'INGRESS',
            'protocol' => $protocol,
            'source' => $r['source'] ?? '0.0.0.0/0',
            'sourceType' => 'CIDR_BLOCK',
            'description' => $r['description'] ?? '',
            'isStateless' => false,
        ];

        $portMin = $r['port_min'] ?? null;
        $portMax = $r['port_max'] ?? $portMin;

        if ($portMin !== null && $protocol === '6') {
            $rule['tcpOptions'] = ['destinationPortRange' => ['min' => (int) $portMin, 'max' => (int) $portMax]];
        } elseif ($portMin !== null && $protocol === '17') {
            $rule['udpOptions'] = ['destinationPortRange' => ['min' => (int) $portMin, 'max' => (int) $portMax]];
        }

        return $rule;
    }
}
