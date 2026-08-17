from __future__ import annotations

import logging
import os
from typing import Any, Dict, Literal, Optional

_log = logging.getLogger(__name__)

from fastapi import Depends, FastAPI, HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel

from .core import ApiKeyConfig, AuthMode, InstancePrincipalConfig, OciConfig
from . import compute, resource_manager as rm

app = FastAPI(title="nimbus-oci-bridge", version="0.1.0")
_bearer = HTTPBearer(auto_error=False)

# ponytail: hardcoded OCI region list; pull from OCI SDK regions module if list grows stale
_OCI_REGIONS = [
    "us-ashburn-1", "us-phoenix-1", "eu-frankfurt-1", "eu-amsterdam-1",
    "ap-sydney-1", "ap-tokyo-1", "ap-singapore-1", "sa-saopaulo-1",
    "uk-london-1", "ca-toronto-1", "me-jeddah-1", "ap-mumbai-1",
]


def _verify_token(creds: Optional[HTTPAuthorizationCredentials] = Security(_bearer)) -> None:
    expected = os.environ.get("OCI_BRIDGE_AUTH_TOKEN")
    if not expected:
        _log.warning("OCI_BRIDGE_AUTH_TOKEN not set — bridge running without authentication")
        return
    if creds is None or creds.credentials != expected:
        raise HTTPException(status_code=401, detail="Unauthorized")


def _parse_config(raw: Dict[str, Any]) -> OciConfig:
    mode = raw.get("auth_mode")
    if mode == AuthMode.INSTANCE_PRINCIPAL or mode == "instance_principal":
        return InstancePrincipalConfig()
    try:
        return ApiKeyConfig(
            tenancy_id=raw["tenancy_id"],
            user_id=raw["user_id"],
            fingerprint=raw["fingerprint"],
            region=raw["region"],
            key_content=raw["key_content"],
            passphrase=raw.get("passphrase"),
        )
    except (KeyError, ValueError) as e:
        raise HTTPException(status_code=422, detail=f"Invalid config: {e}")


def _respond(result: Dict[str, Any]) -> Dict[str, Any]:
    if result.get("success"):
        return {"data": result["data"]}
    raise HTTPException(status_code=422, detail=result.get("error"))


# ── connection ──────────────────────────────────────────────────────────────

class ValidateBody(BaseModel):
    config: Dict[str, Any]


@app.post("/v1/connections/validate")
def validate_connection(body: ValidateBody, _: None = Depends(_verify_token)):
    from .core import validate_connection as _validate
    result = _validate(_parse_config(body.config))
    return {"data": {"ok": result.ok, "auth_mode": result.auth_mode, "operation": result.operation,
                     "error": result.error.__dict__ if result.error else None}}


# ── regions ─────────────────────────────────────────────────────────────────

@app.get("/v1/regions")
def list_regions(_: None = Depends(_verify_token)):
    return {"data": [{"name": r} for r in _OCI_REGIONS]}


# ── compute inventory ────────────────────────────────────────────────────────

class ComputeBody(BaseModel):
    config: Dict[str, Any]


class ComputeWithRegionBody(BaseModel):
    config: Dict[str, Any]
    region: str


class ComputeWithLocationBody(BaseModel):
    config: Dict[str, Any]
    compartment_id: str
    region: str


class SubnetsBody(BaseModel):
    config: Dict[str, Any]
    vcn_id: str
    compartment_id: str
    region: str


@app.post("/v1/compartments")
def list_compartments_post(body: ComputeWithRegionBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_compartments(_parse_config(body.config), body.region))


@app.post("/v1/compute/instances")
def list_instances(body: ComputeWithLocationBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_instances(_parse_config(body.config), body.compartment_id, body.region))


@app.post("/v1/compute/availability-domains")
def list_availability_domains(body: ComputeWithLocationBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_availability_domains(_parse_config(body.config), body.compartment_id, body.region))


@app.post("/v1/compute/shapes")
def list_shapes(body: ComputeWithLocationBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_shapes(_parse_config(body.config), body.compartment_id, body.region))


@app.post("/v1/compute/images")
def list_images(body: ComputeWithLocationBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_images(_parse_config(body.config), body.compartment_id, body.region))


@app.post("/v1/compute/subnets")
def list_subnets(body: SubnetsBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_subnets(_parse_config(body.config), body.vcn_id, body.compartment_id, body.region))


@app.post("/v1/compute/subnets-by-compartment")
def list_subnets_by_compartment(body: ComputeWithLocationBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_subnets_by_compartment(_parse_config(body.config), body.compartment_id, body.region))


@app.post("/v1/compute/vcns")
def list_vcns(body: ComputeWithLocationBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_vcns(_parse_config(body.config), body.compartment_id, body.region))


class NsgBody(BaseModel):
    config: Dict[str, Any]
    nsg_id: str
    region: str


class AddNsgRulesBody(BaseModel):
    config: Dict[str, Any]
    nsg_id: str
    rules: list


class RemoveNsgRulesBody(BaseModel):
    config: Dict[str, Any]
    nsg_id: str
    rule_ids: list


@app.post("/v1/compute/nsg/rules")
def list_nsg_rules(body: NsgBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_nsg_rules(_parse_config(body.config), body.nsg_id, body.region))


@app.post("/v1/compute/nsg/rules/add")
def add_nsg_rules(body: AddNsgRulesBody, _: None = Depends(_verify_token)):
    return _respond(compute.add_nsg_rules(_parse_config(body.config), body.nsg_id, body.rules))


@app.post("/v1/compute/nsg/rules/remove")
def remove_nsg_rules(body: RemoveNsgRulesBody, _: None = Depends(_verify_token)):
    return _respond(compute.remove_nsg_rules(_parse_config(body.config), body.nsg_id, body.rule_ids))


class InstanceStatusBody(BaseModel):
    config: Dict[str, Any]
    instance_id: str
    region: str


class InstanceMetricsBody(BaseModel):
    config: Dict[str, Any]
    instance_id: str
    compartment_id: str
    region: str


class InstanceActionBody(BaseModel):
    config: Dict[str, Any]
    instance_id: str
    region: str
    action: Literal["START", "SOFTSTOP", "SOFTRESET"]


@app.post("/v1/compute/instances/{instance_id}/status")
def get_instance_status(instance_id: str, body: InstanceStatusBody, _: None = Depends(_verify_token)):
    return _respond(compute.get_instance_status(_parse_config(body.config), instance_id, body.region))


@app.post("/v1/compute/instances/{instance_id}/metrics")
def get_instance_metrics(instance_id: str, body: InstanceMetricsBody, _: None = Depends(_verify_token)):
    return _respond(compute.get_instance_metrics(_parse_config(body.config), instance_id, body.compartment_id, body.region))


@app.post("/v1/compute/instances/{instance_id}/action")
def instance_action(instance_id: str, body: InstanceActionBody, _: None = Depends(_verify_token)):
    return _respond(compute.instance_action(_parse_config(body.config), instance_id, body.region, body.action))


# ── resource manager ─────────────────────────────────────────────────────────

class CreateStackBody(BaseModel):
    config: Dict[str, Any]
    name: str
    compartment_id: str
    config_source_type: Literal["ZIP_UPLOAD", "GIT_CONFIG_SOURCE"]
    config_source_params: Dict[str, Any]
    variables: Dict[str, str] = {}


class JobBody(BaseModel):
    config: Dict[str, Any]
    operation: Literal["PLAN", "APPLY", "DESTROY"]
    confirm_destroy: bool = False


@app.post("/v1/stacks")
def create_stack(body: CreateStackBody, _: None = Depends(_verify_token)):
    result = rm.create_stack(
        _parse_config(body.config),
        body.name,
        body.compartment_id,
        body.config_source_type,
        body.config_source_params,
        body.variables,
    )
    return _respond(result)


@app.post("/v1/stacks/{stack_id}/jobs")
def run_job(stack_id: str, body: JobBody, _: None = Depends(_verify_token)):
    result = rm.job_operation(
        _parse_config(body.config),
        stack_id,
        body.operation,
        body.confirm_destroy,
    )
    return _respond(result)


class GetJobBody(BaseModel):
    config: Dict[str, Any]


@app.post("/v1/stacks/jobs/{job_id}/status")
def get_job(job_id: str, body: GetJobBody, _: None = Depends(_verify_token)):
    result = rm.get_job(_parse_config(body.config), job_id)
    return _respond(result)


@app.post("/v1/stacks/jobs/{job_id}/outputs")
def get_job_outputs(job_id: str, body: GetJobBody, _: None = Depends(_verify_token)):
    result = rm.get_stack_outputs(_parse_config(body.config), job_id)
    return _respond(result)


@app.post("/v1/stacks/jobs/{job_id}/logs")
def get_job_logs(job_id: str, body: GetJobBody, _: None = Depends(_verify_token)):
    result = rm.get_job_logs(_parse_config(body.config), job_id)
    return _respond(result)


# ── P4: block volumes ────────────────────────────────────────────────────────

class VolumeAttachmentsBody(BaseModel):
    config: Dict[str, Any]
    compartment_id: str
    region: str


class AttachVolumeBody(BaseModel):
    config: Dict[str, Any]
    instance_id: str
    volume_id: str
    region: str
    read_only: bool = False


class DetachVolumeBody(BaseModel):
    config: Dict[str, Any]
    region: str


@app.post("/v1/compute/instances/{instance_id}/volume-attachments")
def list_volume_attachments(instance_id: str, body: VolumeAttachmentsBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_volume_attachments(_parse_config(body.config), body.compartment_id, instance_id, body.region))


@app.post("/v1/compute/volume-attachments/attach")
def attach_volume(body: AttachVolumeBody, _: None = Depends(_verify_token)):
    return _respond(compute.attach_volume(_parse_config(body.config), body.instance_id, body.volume_id, body.region, body.read_only))


@app.post("/v1/compute/volume-attachments/{attachment_id}/detach")
def detach_volume(attachment_id: str, body: DetachVolumeBody, _: None = Depends(_verify_token)):
    return _respond(compute.detach_volume(_parse_config(body.config), attachment_id, body.region))


# ── P4: load balancers ───────────────────────────────────────────────────────

class LoadBalancerBody(BaseModel):
    config: Dict[str, Any]
    compartment_id: str
    region: str


@app.post("/v1/load-balancers")
def list_load_balancers(body: LoadBalancerBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_load_balancers(_parse_config(body.config), body.compartment_id, body.region))


# ── P4: autonomous databases ─────────────────────────────────────────────────

class AutonomousDatabaseBody(BaseModel):
    config: Dict[str, Any]
    compartment_id: str
    region: str


@app.post("/v1/autonomous-databases")
def list_autonomous_databases(body: AutonomousDatabaseBody, _: None = Depends(_verify_token)):
    return _respond(compute.list_autonomous_databases(_parse_config(body.config), body.compartment_id, body.region))
