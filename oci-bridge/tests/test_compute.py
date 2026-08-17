from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

import oci
import oci.core
import oci.database
import oci.identity
import oci.load_balancer
from oci.exceptions import ServiceError

from oci_bridge.core import ApiKeyConfig
from oci_bridge.compute import (
    list_compartments,
    list_availability_domains,
    list_shapes,
    list_images,
    list_subnets,
    list_vcns,
    list_instances,
    list_nsg_rules,
    add_nsg_rules,
    remove_nsg_rules,
    get_instance_status,
    get_instance_metrics,
    instance_action,
    list_volume_attachments,
    attach_volume,
    detach_volume,
    list_load_balancers,
    list_autonomous_databases,
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

        self._lb_patcher = patch.object(oci.load_balancer, 'LoadBalancerClient')
        self.mock_lb_client_cls = self._lb_patcher.start()
        self.mock_lb_client = self.mock_lb_client_cls.return_value

        self._db_patcher = patch.object(oci.database, 'DatabaseClient')
        self.mock_db_client_cls = self._db_patcher.start()
        self.mock_db_client = self.mock_db_client_cls.return_value

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
        self._lb_patcher.stop()
        self._db_patcher.stop()
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

    def _make_image(self, id, display_name, os, os_version, state='AVAILABLE'):
        img = MagicMock()
        img.id = id
        img.display_name = display_name
        img.operating_system = os
        img.operating_system_version = os_version
        img.lifecycle_state = state
        return img

    def test_list_images_happy_path(self):
        resp = MagicMock()
        resp.data = [
            self._make_image('ocid1.image.oc1..img1', 'Oracle Linux 8', 'Oracle Linux', '8'),
            self._make_image('ocid1.image.oc1..img2', 'Ubuntu 22.04', 'Canonical Ubuntu', '22.04'),
            self._make_image('ocid1.image.oc1..img3', 'Rocky Linux 9', 'Rocky Linux', '9'),
        ]
        self.mock_compute_client.list_images.return_value = resp

        result = list_images(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 3)
        ids = [img['id'] for img in result['data']]
        self.assertIn('ocid1.image.oc1..img1', ids)
        self.assertIn('ocid1.image.oc1..img3', ids)
        self.mock_compute_client.list_images.assert_called_once()

    def test_list_images_filters_deprecated_and_unknown_os(self):
        resp = MagicMock()
        resp.data = [
            self._make_image('available-ubuntu', 'Ubuntu 22.04', 'Canonical Ubuntu', '22.04', 'AVAILABLE'),
            self._make_image('deprecated-oracle', 'Oracle Linux 7', 'Oracle Linux', '7', 'DEPRECATED'),
            self._make_image('deleted-ubuntu', 'Ubuntu 20.04', 'Canonical Ubuntu', '20.04', 'DELETED'),
            self._make_image('windows-img', 'Windows Server 2022', 'Windows', '2022', 'AVAILABLE'),
        ]
        self.mock_compute_client.list_images.return_value = resp

        result = list_images(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 1)
        self.assertEqual(result['data'][0]['id'], 'available-ubuntu')

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

    def test_list_vcns_happy_path(self):
        resp = MagicMock()
        vcn1 = MagicMock()
        vcn1.id = 'ocid1.vcn.oc1..vcn1'
        vcn1.display_name = 'my-vcn'
        vcn1.cidr_blocks = ['10.0.0.0/16']
        vcn1.lifecycle_state = 'AVAILABLE'
        resp.data = [vcn1]
        self.mock_vnet_client.list_vcns.return_value = resp

        result = list_vcns(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 1)
        self.assertEqual(result['data'][0]['id'], 'ocid1.vcn.oc1..vcn1')
        self.assertEqual(result['data'][0]['cidr_blocks'], ['10.0.0.0/16'])
        self.assertEqual(result['data'][0]['lifecycle_state'], 'AVAILABLE')
        self.mock_vnet_client.list_vcns.assert_called_once()

    def test_list_nsg_rules_happy_path(self):
        resp = MagicMock()
        rule1 = MagicMock()
        rule1.id = 'rule-001'
        rule1.protocol = '6'
        rule1.source = '0.0.0.0/0'
        rule1.source_type = 'CIDR_BLOCK'
        rule1.description = 'SSH'
        rule1.tcp_options = MagicMock()
        rule1.tcp_options.destination_port_range = MagicMock(min=22, max=22)
        rule1.udp_options = None
        resp.data = [rule1]
        self.mock_vnet_client.list_network_security_group_security_rules.return_value = resp

        result = list_nsg_rules(self._cfg(), 'nsg-id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 1)
        self.assertEqual(result['data'][0]['id'], 'rule-001')
        self.assertEqual(result['data'][0]['port_min'], 22)
        self.assertEqual(result['data'][0]['port_max'], 22)

    def test_add_nsg_rules_happy_path(self):
        resp = MagicMock()
        added = MagicMock()
        added.id = 'rule-new'
        added.description = 'HTTP'
        resp.data = MagicMock()
        resp.data.security_rules = [added]
        self.mock_vnet_client.add_network_security_group_security_rules.return_value = resp

        result = add_nsg_rules(self._cfg(), 'nsg-id', [{'protocol': 'TCP', 'port_min': 80, 'port_max': 80, 'source': '0.0.0.0/0', 'description': 'HTTP'}])

        self.assertTrue(result['success'])
        self.assertEqual(result['data'][0]['id'], 'rule-new')
        self.mock_vnet_client.add_network_security_group_security_rules.assert_called_once()

    def test_remove_nsg_rules_happy_path(self):
        self.mock_vnet_client.remove_network_security_group_security_rules.return_value = MagicMock()

        result = remove_nsg_rules(self._cfg(), 'nsg-id', ['rule-001', 'rule-002'])

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['removed'], 2)
        self.mock_vnet_client.remove_network_security_group_security_rules.assert_called_once()

    def test_get_instance_status_happy_path(self):
        resp = MagicMock()
        resp.data.id = 'ocid1.instance.oc1..inst1'
        resp.data.display_name = 'my-vm'
        resp.data.lifecycle_state = 'RUNNING'
        resp.data.shape = 'VM.Standard.E2.1'
        resp.data.time_created = None
        self.mock_compute_client.get_instance.return_value = resp

        result = get_instance_status(self._cfg(), 'ocid1.instance.oc1..inst1', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['lifecycle_state'], 'RUNNING')
        self.mock_compute_client.get_instance.assert_called_once_with(instance_id='ocid1.instance.oc1..inst1', retry_strategy=unittest.mock.ANY)

    def test_instance_action_happy_path(self):
        resp = MagicMock()
        resp.data.lifecycle_state = 'STOPPING'
        self.mock_compute_client.instance_action.return_value = resp

        result = instance_action(self._cfg(), 'ocid1.instance.oc1..inst1', 'us-ashburn-1', 'SOFTSTOP')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['lifecycle_state'], 'STOPPING')

    def test_instance_action_invalid_action(self):
        result = instance_action(self._cfg(), 'ocid1.instance.oc1..inst1', 'us-ashburn-1', 'DELETE')

        self.assertFalse(result['success'])
        self.assertEqual(result['error']['category'], 'validation')

    def test_get_instance_metrics_happy_path(self):
        with patch('oci.monitoring.MonitoringClient') as mock_monitoring_cls:
            mock_client = mock_monitoring_cls.return_value
            pt = MagicMock()
            pt.value = 45.5
            metric_resp = MagicMock()
            metric_resp.data = [MagicMock(aggregated_datapoints=[pt])]
            mock_client.summarize_metrics_data.return_value = metric_resp

            result = get_instance_metrics(self._cfg(), 'inst-id', 'comp-id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        # cpu_percent comes from oci_compute namespace; may be None in mock if not wired
        self.assertIn('cpu_percent', result['data'])
        self.assertIn('memory_percent', result['data'])

    def test_service_error_returns_failure(self):
        err = ServiceError(status=404, code='NotAuthorizedOrNotFound', headers={}, message='test')
        cases = [
            (self.mock_identity_client.list_compartments, list_compartments, (self._cfg(), 'us-ashburn-1')),
            (self.mock_identity_client.list_availability_domains, list_availability_domains, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_compute_client.list_shapes, list_shapes, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_compute_client.list_images, list_images, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_vnet_client.list_subnets, list_subnets, (self._cfg(), 'vcn_id', 'comp_id', 'us-ashburn-1')),
            (self.mock_vnet_client.list_vcns, list_vcns, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_compute_client.list_instances, list_instances, (self._cfg(), 'comp_id', 'us-ashburn-1')),
            (self.mock_vnet_client.list_network_security_group_security_rules, list_nsg_rules, (self._cfg(), 'nsg-id', 'us-ashburn-1')),
            (self.mock_compute_client.get_instance, get_instance_status, (self._cfg(), 'inst-id', 'us-ashburn-1')),
            (self.mock_compute_client.instance_action, instance_action, (self._cfg(), 'inst-id', 'us-ashburn-1', 'START')),
        ]
        for mock_method, fn, args in cases:
            with self.subTest(fn=fn.__name__):
                mock_method.side_effect = err
                result = fn(*args)
                self.assertFalse(result['success'])
                self.assertEqual(result['error']['status'], 404)
                self.assertEqual(result['error']['category'], 'service')
                mock_method.side_effect = None

    # ── P4 tests ─────────────────────────────────────────────────────────────

    def test_list_volume_attachments_happy_path(self):
        resp = MagicMock()
        att = MagicMock()
        att.id = 'ocid1.volumeattachment.oc1..att1'
        att.volume_id = 'ocid1.volume.oc1..vol1'
        att.display_name = 'vol1-attach'
        att.lifecycle_state = 'ATTACHED'
        att.attachment_type = 'paravirtualized'
        att.is_read_only = False
        resp.data = [att]
        self.mock_compute_client.list_volume_attachments.return_value = resp

        result = list_volume_attachments(self._cfg(), 'comp_id', 'inst-id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 1)
        self.assertEqual(result['data'][0]['id'], 'ocid1.volumeattachment.oc1..att1')
        self.assertEqual(result['data'][0]['volume_id'], 'ocid1.volume.oc1..vol1')
        self.assertEqual(result['data'][0]['lifecycle_state'], 'ATTACHED')
        self.assertFalse(result['data'][0]['is_read_only'])

    def test_list_volume_attachments_empty(self):
        resp = MagicMock()
        resp.data = []
        self.mock_compute_client.list_volume_attachments.return_value = resp

        result = list_volume_attachments(self._cfg(), 'comp_id', 'inst-id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(result['data'], [])

    def test_attach_volume_happy_path(self):
        mock_resp = MagicMock()
        mock_resp.data.id = 'ocid1.volumeattachment.oc1..new'
        mock_resp.data.volume_id = 'ocid1.volume.oc1..vol1'
        mock_resp.data.lifecycle_state = 'ATTACHING'
        mock_resp.data.attachment_type = 'paravirtualized'
        self.mock_compute_client.attach_volume.return_value = mock_resp

        result = attach_volume(self._cfg(), 'inst-id', 'ocid1.volume.oc1..vol1', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['id'], 'ocid1.volumeattachment.oc1..new')
        self.assertEqual(result['data']['lifecycle_state'], 'ATTACHING')
        self.mock_compute_client.attach_volume.assert_called_once()

    def test_attach_volume_service_error(self):
        err = ServiceError(status=409, code='VolumeAlreadyAttached', headers={}, message='Volume already attached')
        self.mock_compute_client.attach_volume.side_effect = err

        result = attach_volume(self._cfg(), 'inst-id', 'ocid1.volume.oc1..vol1', 'us-ashburn-1')

        self.assertFalse(result['success'])
        self.assertEqual(result['error']['status'], 409)
        self.assertEqual(result['error']['category'], 'service')

    def test_detach_volume_happy_path(self):
        self.mock_compute_client.detach_volume.return_value = None

        result = detach_volume(self._cfg(), 'ocid1.volumeattachment.oc1..att1', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertTrue(result['data']['detached'])
        self.mock_compute_client.detach_volume.assert_called_once_with(
            volume_attachment_id='ocid1.volumeattachment.oc1..att1',
            retry_strategy=unittest.mock.ANY,
        )

    def test_list_load_balancers_happy_path(self):
        resp = MagicMock()
        lb = MagicMock()
        lb.id = 'ocid1.loadbalancer.oc1..lb1'
        lb.display_name = 'prod-lb'
        lb.lifecycle_state = 'ACTIVE'
        lb.shape_name = 'flexible'
        ip = MagicMock()
        ip.ip_address = '10.0.0.1'
        lb.ip_addresses = [ip]
        lb.backend_sets = {'bs1': MagicMock(), 'bs2': MagicMock()}
        resp.data = [lb]
        self.mock_lb_client.list_load_balancers.return_value = resp

        result = list_load_balancers(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 1)
        self.assertEqual(result['data'][0]['display_name'], 'prod-lb')
        self.assertEqual(result['data'][0]['ip_addresses'], ['10.0.0.1'])
        self.assertIn('bs1', result['data'][0]['backend_sets'])

    def test_list_autonomous_databases_happy_path(self):
        resp = MagicMock()
        adb = MagicMock()
        adb.id = 'ocid1.autonomousdatabase.oc1..adb1'
        adb.display_name = 'prod-adb'
        adb.db_name = 'PRODADB'
        adb.lifecycle_state = 'AVAILABLE'
        adb.db_workload = 'OLTP'
        adb.cpu_core_count = 2
        adb.data_storage_size_in_tbs = 1
        adb.connection_strings.all_connection_strings = {'high': 'jdbc:...high', 'medium': 'jdbc:...medium'}
        resp.data = [adb]
        self.mock_db_client.list_autonomous_databases.return_value = resp

        result = list_autonomous_databases(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertEqual(len(result['data']), 1)
        self.assertEqual(result['data'][0]['display_name'], 'prod-adb')
        self.assertEqual(result['data'][0]['db_workload'], 'OLTP')
        self.assertIn('high', result['data'][0]['connection_strings'])

    def test_list_autonomous_databases_no_connection_strings(self):
        resp = MagicMock()
        adb = MagicMock()
        adb.id = 'ocid1.autonomousdatabase.oc1..adb1'
        adb.display_name = 'stopped-adb'
        adb.db_name = 'STOPADB'
        adb.lifecycle_state = 'STOPPED'
        adb.db_workload = 'DW'
        adb.cpu_core_count = 1
        adb.data_storage_size_in_tbs = 1
        adb.connection_strings = None
        resp.data = [adb]
        self.mock_db_client.list_autonomous_databases.return_value = resp

        result = list_autonomous_databases(self._cfg(), 'comp_id', 'us-ashburn-1')

        self.assertTrue(result['success'])
        self.assertIsNone(result['data'][0]['connection_strings'])
