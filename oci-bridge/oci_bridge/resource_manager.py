from __future__ import annotations

import oci
from typing import Any, Dict, Literal, Optional
from oci.exceptions import ServiceError

from .core import ApiKeyConfig, InstancePrincipalConfig, OciConfig, build_authentication, _normalize_error as _normalize_core_error

OciConfig = ApiKeyConfig | InstancePrincipalConfig

def create_stack(config: OciConfig, name: str, compartment_id: str, config_source_type: Literal['ZIP_UPLOAD', 'GIT_CONFIG_SOURCE'], config_source_params: Dict[str, Any], variables: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.resource_manager.ResourceManagerClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        if config_source_type == 'ZIP_UPLOAD':
            config_src = oci.resource_manager.models.CreateZipUploadConfigSourceDetails(
                zip_file_base64_encoded=config_source_params.get('zip_file_base64_encoded', '')
            )
        elif config_source_type == 'GIT_CONFIG_SOURCE':
            config_src = oci.resource_manager.models.CreateGitConfigSourceDetails(**config_source_params)
        else:
            raise ValueError(f'Unsupported config_source_type: {config_source_type}')

        create_details = oci.resource_manager.models.CreateStackDetails(
            display_name=name,
            compartment_id=compartment_id,
            config_source=config_src,
            variables=variables or {},
            terraform_version='1.5.x',  # ponytail: pinned; expose as param when multi-version needed
        )
        response = client.create_stack(
            create_stack_details=create_details,
            retry_strategy=retry_strategy
        )
        return {
            'success': True,
            'data': {
                'stack_id': response.data.id,
                'display_name': response.data.display_name
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

def job_operation(config: OciConfig, stack_id: str, operation: Literal['PLAN', 'APPLY', 'DESTROY'], confirm_destroy: bool = False) -> Dict[str, Any]:
    # ponytail: guard before auth to avoid unnecessary credential validation
    if operation == 'DESTROY' and not confirm_destroy:
        return {
            'success': False,
            'error': {'message': 'Confirmation required for destroy operation'},
        }
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.resource_manager.ResourceManagerClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        if operation == 'PLAN':
            work_request = client.plan_stack(
                stack_id=stack_id,
                retry_strategy=retry_strategy
            )
        elif operation == 'APPLY':
            work_request = client.apply_stack(
                stack_id=stack_id,
                retry_strategy=retry_strategy
            )
        elif operation == 'DESTROY':
            work_request = client.destroy_stack(
                stack_id=stack_id,
                retry_strategy=retry_strategy
            )
        else:
            raise ValueError('Invalid operation')
        return {
            'success': True,
            'data': {
                'job_id': work_request.data.id,
                'operation': operation.lower()
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

def get_stack_outputs(config: OciConfig, job_id: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.resource_manager.ResourceManagerClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        response = client.get_job_tf_state(job_id=job_id, retry_strategy=retry_strategy)
        import json
        state = json.loads(response.data.read().decode('utf-8'))
        outputs = state.get('outputs', {})
        return {
            'success': True,
            'data': {k: v.get('value') for k, v in outputs.items()}
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


def get_job(config: OciConfig, job_id: str) -> Dict[str, Any]:
    authentication = build_authentication(config)
    client_kwargs = {
        'config': authentication.sdk_config,
        'signer': authentication.signer
    }
    client = oci.resource_manager.ResourceManagerClient(**client_kwargs)
    retry_strategy = oci.retry.NoneRetryStrategy()

    try:
        work_request = client.get_work_request(
            work_request_id=job_id,
            retry_strategy=retry_strategy
        )
        return {
            'success': True,
            'data': {
                'status': work_request.data.status.value,
                'lifecycle_state': work_request.data.lifecycle_state,
                'logs': [log.message for log in getattr(work_request.data, 'log_entries', [])] if hasattr(work_request.data, 'log_entries') else []
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