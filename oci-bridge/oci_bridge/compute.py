from __future__ import annotations

import oci
from typing import Any, Dict, Literal, Optional
from oci.exceptions import ServiceError

from .core import ApiKeyConfig, InstancePrincipalConfig, OciConfig, build_authentication

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
            if img.operating_system in ('Oracle Linux', 'Canonical Ubuntu'):
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
