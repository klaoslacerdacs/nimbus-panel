from __future__ import annotations

import json
import os
from unittest.mock import MagicMock, patch

import pytest
from fastapi.testclient import TestClient

from oci_bridge.app import app

client = TestClient(app, raise_server_exceptions=True)


def test_regions_no_auth():
    resp = client.get("/v1/regions")
    assert resp.status_code == 200
    data = resp.json()["data"]
    assert any(r["name"] == "us-ashburn-1" for r in data)


def test_token_required_when_set(monkeypatch):
    monkeypatch.setenv("OCI_BRIDGE_AUTH_TOKEN", "secret")
    resp = client.get("/v1/regions")
    assert resp.status_code == 401


def test_token_accepted(monkeypatch):
    monkeypatch.setenv("OCI_BRIDGE_AUTH_TOKEN", "secret")
    resp = client.get("/v1/regions", headers={"Authorization": "Bearer secret"})
    assert resp.status_code == 200


@patch("oci_bridge.app.compute.list_instances")
def test_list_instances_proxies_result(mock_fn):
    mock_fn.return_value = {"success": True, "data": [{"id": "ocid1.instance.x"}]}
    body = {"config": {"auth_mode": "instance_principal"}}
    resp = client.post("/v1/compute/instances?compartment_id=ocid1.c.x&region=us-ashburn-1", json=body)
    assert resp.status_code == 200
    assert resp.json()["data"][0]["id"] == "ocid1.instance.x"


@patch("oci_bridge.app.rm.create_stack")
def test_create_stack_proxies_result(mock_fn):
    mock_fn.return_value = {"success": True, "data": {"stack_id": "ocid1.ormstack.x", "display_name": "test"}}
    body = {
        "config": {"auth_mode": "instance_principal"},
        "name": "test",
        "compartment_id": "ocid1.c.x",
        "config_source_type": "ZIP_UPLOAD",
        "config_source_params": {},
    }
    resp = client.post("/v1/stacks", json=body)
    assert resp.status_code == 200
    assert resp.json()["data"]["stack_id"] == "ocid1.ormstack.x"


@patch("oci_bridge.app.rm.job_operation")
def test_run_job_proxies_result(mock_fn):
    mock_fn.return_value = {"success": True, "data": {"job_id": "ocid1.ormjob.x", "operation": "plan"}}
    body = {"config": {"auth_mode": "instance_principal"}, "operation": "PLAN"}
    resp = client.post("/v1/stacks/ocid1.ormstack.x/jobs", json=body)
    assert resp.status_code == 200
    assert resp.json()["data"]["operation"] == "plan"
