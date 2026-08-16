from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

import oci
import oci.core
import oci.identity
from oci.exceptions import ServiceError

from oci_bridge.core import ApiKeyConfig
from oci_bridge.compute import (
    list_compartments,
    list_availability_domains,
    list_shapes,
    list_images,
    list_subnets,
    list_instances
)

def _fake_rsa_key():
    # ponytail: real RSA needed — core.py checks isinstance(key, RSAPrivateKey); MagicMock fails it
    from cryptography.hazmat.backends import default_backend
    from cryptography.hazmat.primitives.asymmetric import rsa
    return rsa.generate_private_key(public_exponent=65537, key_size=2048, backend=default_backend())

def _fake_auth():
    auth = MagicMock()
    auth.sdk_config = {}
    auth.signer = MagicMock()
    return auth

_VALID_CONFIG = dict(
    tenancy_id='ocid1.tenancy.oc1..aaa',
    user_id='ocid1.user.oc1..bbb',
    fingerprint='aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
    region='us-ashburn-1',
    key_content='k',
)

class TestCompute(unittest.TestCase):

    def setUp(self):
        self._key_patcher = patch('oci.signer.load_private_key', return_value=_fake_rsa_key())
        self._key_patcher.start()

        self._auth_patcher = patch('oci_bridge.compute.build_authentication', return_value=_fake_auth())
        self._auth_patcher.start()

        self._identity_client_patcher = patch.object(oci.identity, 'IdentityClient')
        self.mock_identity_client_cls = self._identity_client_patcher.start()
        self.mock_identity_client = self.mock_identity_client_cls.return_value

        self._core_patcher = patch.object(oci.core, 'ComputeClient')
        self.mock_compute_client_cls = self._core_patcher.start()
        self.mock_compute_client = self.mock_compute_client_cls.return_value

        self._vnet_patcher = patch.object(oci.core, 'VirtualNetworkClient')
        self.mock_vnet_client_cls = self._vnet_patcher.start()
        self.mock_vnet_client = self.mock_vnet_client_cls.return_value

        # ponytail: makes list_call_get_all_results a pass-through so client mocks work
        self._pagination_patcher = patch(
            'oci.pagination.list_call_get_all_results',
            side_effect=lambda fn, *args, **kwargs: fn(*args, **kwargs)
        )
        self._pagination_patcher.start()

    def tearDown(self):
        self._key_patcher.stop()
        self._auth_patcher.stop()
        self._identity_client_patcher.stop()
        self._core_patcher.stop()
        self._vnet_patcher.stop()
        self._pagination_patcher.stop()

    def _cfg(self):
        return ApiKeyConfig(**_VALID_CONFIG)

    def test_list_compartments_happy_path(self):
        resp = MagicMock()
        comp1 = MagicMock()
        comp1.id = 'ocid1.compartment.oc1..comp1'
        comp1.name = 'comp1'
        comp1.description = 'desc1'
        comp2 = MagicMock()
        comp2.id = 'ocid1.compartment.oc1..comp2'
        comp2.name = 'comp2'
        comp2.description = 'desc2'
        resp.data = [comp1, comp2]
        self.mock_identity_client.list_compartments.return_value = resp

        result = list_compartments(self._cfg(), 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 2)
        self.assertEqual(result['data'][0]['id'], 'ocid1.compartment.oc1..comp1')
        self.assertEqual(result['data'][0]['name'], 'comp1')
        self.assertEqual(result['data'][0]['description'], 'desc1')
        self.mock_identity_client.list_compartments.assert_called_once()

    def test_list_availability_domains_happy_path(self):
        resp = MagicMock()
        dom1 = MagicMock()
        dom1.name = 'ad1'
        dom1.id = 'id1'
        dom2 = MagicMock()
        dom2.name = 'ad2'
        dom2.id = 'id2'
        resp.data = [dom1, dom2]
        self.mock_identity_client.list_availability_domains.return_value = resp

        result = list_availability_domains(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 2)
        self.assertEqual(result['data'][0]['name'], 'ad1')
        self.assertEqual(result['data'][0]['id'], 'id1')
        self.mock_identity_client.list_availability_domains.assert_called_once()

    def test_list_shapes_happy_path(self):
        resp = MagicMock()
        shape1 = MagicMock()
        shape1.shape = 'VM.Standard.E2.1'
        shape1.ocpu_count = 1
        shape1.memory_in_gbs = 8
        shape1.is_flexible = True
        shape2 = MagicMock()
        shape2.shape = 'VM.Standard.E2.2'
        shape2.ocpu_count = 2
        shape2.memory_in_gbs = 16
        shape2.is_flexible = True
        resp.data = [shape1, shape2]
        self.mock_compute_client.list_shapes.return_value = resp

        result = list_shapes(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 2)
        self.assertEqual(result['data'][0]['name'], 'VM.Standard.E2.1')
        self.assertEqual(result['data'][0]['ocpus'], 1)
        self.assertEqual(result['data'][0]['memory_in_gbs'], 8)
        self.assertEqual(result['data'][0]['is_flexible'], True)
        self.mock_compute_client.list_shapes.assert_called_once()

    def test_list_images_happy_path(self):
        resp = MagicMock()
        img1 = MagicMock()
        img1.id = 'ocid1.image.oc1..img1'
        img1.display_name = 'Oracle Linux 8'
        img1.operating_system = 'Oracle Linux'
        img1.operating_system_version = '8'
        img2 = MagicMock()
        img2.id = 'ocid1.image.oc1..img2'
        img2.display_name = 'Ubuntu 20.04'
        img2.operating_system = 'Canonical Ubuntu'
        img2.operating_system_version = '20.04'
        resp.data = [img1, img2]
        self.mock_compute_client.list_images.return_value = resp

        result = list_images(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 2)
        self.assertEqual(result['data'][0]['id'], 'ocid1.image.oc1..img1')
        self.assertEqual(result['data'][0]['operating_system'], 'Oracle Linux')
        self.assertEqual(result['data'][0]['os_version'], '8')
        self.mock_compute_client.list_images.assert_called_once()

    def test_list_subnets_happy_path(self):
        resp = MagicMock()
        subnet1 = MagicMock()
        subnet1.id = 'ocid1.subnet.oc1..sub1'
        subnet1.display_name = 'subnet1'
        subnet1.cidr_block = '10.0.0.0/24'
        subnet1.availability_domain = 'ad1'
        subnet2 = MagicMock()
        subnet2.id = 'ocid1.subnet.oc1..sub2'
        subnet2.display_name = 'subnet2'
        subnet2.cidr_block = '10.0.1.0/24'
        subnet2.availability_domain = 'ad2'
        resp.data = [subnet1, subnet2]
        self.mock_vnet_client.list_subnets.return_value = resp

        result = list_subnets(self._cfg(), 'vcn_id', 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 2)
        self.assertEqual(result['data'][0]['id'], 'ocid1.subnet.oc1..sub1')
        self.assertEqual(result['data'][0]['cidr_block'], '10.0.0.0/24')
        self.assertEqual(result['data'][0]['availability_domain'], 'ad1')
        self.mock_vnet_client.list_subnets.assert_called_once()

    def test_list_instances_happy_path(self):
        resp = MagicMock()
        inst1 = MagicMock()
        inst1.id = 'ocid1.instance.oc1..inst1'
        inst1.display_name = 'instance1'
        inst1.lifecycle_state = 'RUNNING'
        inst1.shape = 'VM.Standard.E2.1'
        inst2 = MagicMock()
        inst2.id = 'ocid1.instance.oc1..inst2'
        inst2.display_name = 'instance2'
        inst2.lifecycle_state = 'STOPPED'
        inst2.shape = 'VM.Standard.E2.2'
        resp.data = [inst1, inst2]
        self.mock_compute_client.list_instances.return_value = resp

        result = list_instances(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 2)
        self.assertEqual(result['data'][0]['id'], 'ocid1.instance.oc1..inst1')
        self.assertEqual(result['data'][0]['lifecycle_state'], 'RUNNING')
        self.assertEqual(result['data'][0]['shape'], 'VM.Standard.E2.1')
        self.assertEqual(result['data'][0]['region'], 'us-ashburn-1')
        self.mock_compute_client.list_instances.assert_called_once()

    def test_service_error_returns_failure(self):
        err = ServiceError(status=404, code='NotAuthorizedOrNotFound', headers={}, message='test')
        cases = [
            (self.mock_identity_client.list_compartments, list_compartments, (self._cfg(), 'us-ashburn-1')),
            (self.mock_identity_client.list_availability_domains, list_availability_domains, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_compute_client.list_shapes, list_shapes, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_compute_client.list_images, list_images, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_vnet_client.list_subnets, list_subnets, (self._cfg(), 'vcn_id', 'comp_id', 'us-ashburn-1')),
            (self.mock_compute_client.list_instances, list_instances, (self._cfg(), 'comp_id', 'us-ashburn-1')),
        ]
        for mock_method, fn, args in cases:
            with self.subTest(fn=fn.__name__):
                mock_method.side_effect = err
                result = fn(*args)
                self.assertFalse(result['success'])
                self.assertEqual(result['error']['status'], 404)
                self.assertEqual(result['error']['category'], 'service')
                mock_method.side_effect = None
