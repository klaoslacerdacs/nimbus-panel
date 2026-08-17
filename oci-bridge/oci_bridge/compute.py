from __future__ import annotations

import oci
from typing import Any, Dict, Literal, Optional
from oci.exceptions import ServiceError

from .core import ApiKeyConfig, InstancePrincipalConfig, OciConfig, build_authentication
import datetime

def list_compartments(config: OciConfig, region: str) -> Dict[str, Any]:
    # ponytail: auth block repeated per-function; extract _make_client(config, cls) when a 7th function is added
    # ponytail: error handler repeated per-function; extract _oci_call() if a new error field is needed across functions
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.identity.IdentityClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    if isinstance(config, ApiKeyConfig):
        tenancy_id = config.tenancy_id
    else:
        tenancy_id = authentication.sdk_config.get('tenancy')
        if not tenancy_id:
            raise ValueError('Cannot determine tenancy OCID for Instance Principal auth — ensure the instance has the correct IAM policy')

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_compartments,
            compartment_id=tenancy_id,
            retry_strategy=retry_strategy
        )
        compartments = []
        for comp in response.data:
            compartments.append({
                'id': comp.id,
                'name': comp.name,
                'description': comp.description
            })
        return {
            'success': True,
            'data': compartments
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id
            }
        }
    except Exception as e:
        return {
            'success': False,
            'error': {
                'category': 'unexpected',
                'exception_type': type(e).__name__
            }
        }

def list_availability_domains(config: OciConfig, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.identity.IdentityClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_availability_domains,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        domains = []
        for dom in response.data:
            domains.append({
                'name': dom.name,
                'id': dom.id
            })
        return {
            'success': True,
            'data': domains
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id
            }
        }
    except Exception as e:
        return {
            'success': False,
            'error': {
                'category': 'unexpected',
                'exception_type': type(e).__name__
            }
        }

def list_shapes(config: OciConfig, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.ComputeClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_shapes,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        shapes = []
        for shape in response.data:
            shapes.append({
                'name': shape.shape,
                'ocpus': shape.ocpu_count,
                'memory_in_gbs': shape.memory_in_gbs,
                'is_flexible': shape.is_flexible
            })
        return {
            'success': True,
            'data': shapes
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id
            }
        }
    except Exception as e:
        return {
            'success': False,
            'error': {
                'category': 'unexpected',
                'exception_type': type(e).__name__
            }
        }

def list_images(config: OciConfig, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.ComputeClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_images,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        images = []
        for img in response.data:
            if img.operating_system in ('Oracle Linux', 'Canonical Ubuntu', 'Rocky Linux'):
                images.append({
                    'id': img.id,
                    'display_name': img.display_name,
                    'operating_system': img.operating_system,
                    'os_version': img.operating_system_version
                })
        return {
            'success': True,
            'data': images
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id
            }
        }
    except Exception as e:
        return {
            'success': False,
            'error': {
                'category': 'unexpected',
                'exception_type': type(e).__name__
            }
        }

def list_subnets_by_compartment(config: OciConfig, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.VirtualNetworkClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_subnets,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        subnets = [
            {
                'id': s.id,
                'display_name': s.display_name,
                'cidr_block': s.cidr_block,
                'availability_domain': s.availability_domain,
            }
            for s in response.data
        ]
        return {'success': True, 'data': subnets}
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}


def list_subnets(config: OciConfig, vcn_id: str, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.VirtualNetworkClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_subnets,
            vcn_id=vcn_id,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        subnets = []
        for subnet in response.data:
            subnets.append({
                'id': subnet.id,
                'display_name': subnet.display_name,
                'cidr_block': subnet.cidr_block,
                'availability_domain': subnet.availability_domain
            })
        return {
            'success': True,
            'data': subnets
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id
            }
        }
    except Exception as e:
        return {
            'success': False,
            'error': {
                'category': 'unexpected',
                'exception_type': type(e).__name__
            }
        }

def list_vcns(config: OciConfig, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.VirtualNetworkClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_vcns,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        vcns = [
            {
                'id': v.id,
                'display_name': v.display_name,
                'cidr_blocks': v.cidr_blocks,
                'lifecycle_state': v.lifecycle_state,
            }
            for v in response.data
        ]
        return {'success': True, 'data': vcns}
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}

def list_instances(config: OciConfig, compartment_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.ComputeClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_instances,
            compartment_id=compartment_id,
            retry_strategy=retry_strategy
        )
        instances = []
        for inst in response.data:
            instances.append({
                'id': inst.id,
                'display_name': inst.display_name,
                'lifecycle_state': inst.lifecycle_state,
                'shape': inst.shape,
                'region': region
            })
        return {
            'success': True,
            'data': instances
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id
            }
        }
    except Exception as e:
        return {
            'success': False,
            'error': {
                'category': 'unexpected',
                'exception_type': type(e).__name__
            }
        }


def list_nsg_rules(config: OciConfig, nsg_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.VirtualNetworkClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = oci.pagination.list_call_get_all_results(
            client.list_network_security_group_security_rules,
            network_security_group_id=nsg_id,
            direction='INGRESS',
            retry_strategy=retry_strategy
        )
        rules = []
        for r in response.data:
            rule = {
                'id': r.id,
                'protocol': r.protocol,
                'source': r.source,
                'source_type': r.source_type,
                'description': r.description or '',
            }
            if hasattr(r, 'tcp_options') and r.tcp_options:
                dpr = r.tcp_options.destination_port_range
                if dpr:
                    rule['port_min'] = dpr.min
                    rule['port_max'] = dpr.max
            elif hasattr(r, 'udp_options') and r.udp_options:
                dpr = r.udp_options.destination_port_range
                if dpr:
                    rule['port_min'] = dpr.min
                    rule['port_max'] = dpr.max
            rules.append(rule)
        return {'success': True, 'data': rules}
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}


def add_nsg_rules(config: OciConfig, nsg_id: str, rules: list) -> Dict[str, Any]:
    """rules: list of {protocol, port_min, port_max, source, description}
    protocol: 'TCP'|'UDP'|'ICMP'|'ALL'
    """
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.VirtualNetworkClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    def _build_rule(r: dict):
        proto_map = {'TCP': '6', 'UDP': '17', 'ICMP': '1', 'ALL': 'all'}
        protocol = proto_map.get(r.get('protocol', 'TCP').upper(), '6')

        kwargs = {
            'direction': 'INGRESS',
            'protocol': protocol,
            'source': r.get('source', '0.0.0.0/0'),
            'source_type': 'CIDR_BLOCK',
            'description': r.get('description', ''),
            'is_stateless': False,
        }

        port_min = r.get('port_min')
        port_max = r.get('port_max', port_min)

        if protocol == '6' and port_min is not None:
            kwargs['tcp_options'] = oci.core.models.TcpOptions(
                destination_port_range=oci.core.models.PortRange(min=int(port_min), max=int(port_max))
            )
        elif protocol == '17' and port_min is not None:
            kwargs['udp_options'] = oci.core.models.UdpOptions(
                destination_port_range=oci.core.models.PortRange(min=int(port_min), max=int(port_max))
            )

        return oci.core.models.AddSecurityRuleDetails(**kwargs)

    try:
        security_rules = [_build_rule(r) for r in rules]
        details = oci.core.models.AddNetworkSecurityGroupSecurityRulesDetails(
            security_rules=security_rules
        )
        response = client.add_network_security_group_security_rules(
            network_security_group_id=nsg_id,
            add_network_security_group_security_rules_details=details,
            retry_strategy=retry_strategy,
        )
        added = [{'id': r.id, 'description': r.description or ''} for r in (response.data.security_rules or [])]
        return {'success': True, 'data': added}
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}


def remove_nsg_rules(config: OciConfig, nsg_id: str, rule_ids: list) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.VirtualNetworkClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        details = oci.core.models.RemoveNetworkSecurityGroupSecurityRulesDetails(
            security_rule_ids=rule_ids
        )
        client.remove_network_security_group_security_rules(
            network_security_group_id=nsg_id,
            remove_network_security_group_security_rules_details=details,
            retry_strategy=retry_strategy,
        )
        return {'success': True, 'data': {'removed': len(rule_ids)}}
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}


def get_instance_status(config: OciConfig, instance_id: str, region: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.ComputeClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = client.get_instance(instance_id=instance_id, retry_strategy=retry_strategy)
        inst = response.data
        return {
            'success': True,
            'data': {
                'id': inst.id,
                'display_name': inst.display_name,
                'lifecycle_state': inst.lifecycle_state,
                'shape': inst.shape,
                'region': region,
                'time_created': str(inst.time_created) if inst.time_created else None,
            }
        }
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}


def get_instance_metrics(config: OciConfig, instance_id: str, compartment_id: str, region: str) -> Dict[str, Any]:
    # ponytail: CPU via oci_compute (no agent); memory/network via oci_computeagent (agent required) — returns null when unavailable
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.monitoring.MonitoringClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    end_time = datetime.datetime.now(datetime.timezone.utc)
    start_time = end_time - datetime.timedelta(minutes=10)

    def _query(namespace: str, query: str) -> Optional[float]:
        try:
            details = oci.monitoring.models.SummarizeMetricsDataDetails(
                namespace=namespace,
                query=query,
                start_time=start_time,
                end_time=end_time,
                resolution='5m',
            )
            resp = client.summarize_metrics_data(
                compartment_id=compartment_id,
                summarize_metrics_data_details=details,
                retry_strategy=retry_strategy,
            )
            if resp.data and resp.data[0].aggregated_datapoints:
                return resp.data[0].aggregated_datapoints[-1].value
            return None
        except Exception:
            return None

    dim = f'resourceId = "{instance_id}"'
    cpu = _query('oci_compute', f'CpuUtilization[5m]{{{dim}}}.mean()')
    memory = _query('oci_computeagent', f'MemoryUtilization[5m]{{{dim}}}.mean()')
    net_in = _query('oci_computeagent', f'NetworksBytesIn[5m]{{{dim}}}.mean()')
    net_out = _query('oci_computeagent', f'NetworksBytesOut[5m]{{{dim}}}.mean()')

    return {
        'success': True,
        'data': {
            'cpu_percent': round(cpu, 2) if cpu is not None else None,
            'memory_percent': round(memory, 2) if memory is not None else None,
            'net_bytes_in': int(net_in) if net_in is not None else None,
            'net_bytes_out': int(net_out) if net_out is not None else None,
        }
    }


def instance_action(config: OciConfig, instance_id: str, region: str, action: str) -> Dict[str, Any]:
    """action: START | SOFTSTOP | SOFTRESET"""
    if action.upper() not in {'START', 'SOFTSTOP', 'SOFTRESET'}:
        return {'success': False, 'error': {'category': 'validation', 'message': 'action must be START, SOFTSTOP, or SOFTRESET'}}

    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.core.ComputeClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = client.instance_action(
            instance_id=instance_id,
            action=action.upper(),
            retry_strategy=retry_strategy,
        )
        return {'success': True, 'data': {'lifecycle_state': response.data.lifecycle_state}}
    except ServiceError as e:
        return {
            'success': False,
            'error': {
                'category': 'service',
                'exception_type': type(e).__name__,
                'status': e.status,
                'code': e.code,
                'request_id': e.request_id,
            },
        }
    except Exception as e:
        return {'success': False, 'error': {'category': 'unexpected', 'exception_type': type(e).__name__}}
