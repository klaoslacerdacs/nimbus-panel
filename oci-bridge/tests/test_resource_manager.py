from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch, patch as _patch

import oci
import oci.resource_manager
from oci.exceptions import ServiceError

from oci_bridge.resource_manager import create_stack, get_job, get_job_logs, job_operation


def _fake_rsa_key():
    from cryptography.hazmat.backends import default_backend
    from cryptography.hazmat.primitives.asymmetric import rsa
    return rsa.generate_private_key(public_exponent=65537, key_size=2048, backend=default_backend())


def _fake_auth() -> MagicMock:
    """ponytail: shared mock auth object; avoids real OCI key/metadata calls."""
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


class TestResourceManager(unittest.TestCase):

    def setUp(self):
        # Allow ApiKeyConfig instantiation with fake key
        self._key_patcher = patch('oci.signer.load_private_key', return_value=_fake_rsa_key())
        self._key_patcher.start()

        # Mock build_authentication so no real OCI calls happen
        self._auth_patcher = patch('oci_bridge.resource_manager.build_authentication', return_value=_fake_auth())
        self._auth_patcher.start()

        # Mock the ResourceManagerClient class and models at oci.resource_manager module level
        self._client_patcher = patch.object(oci.resource_manager, 'ResourceManagerClient')
        self.mock_client_cls = self._client_patcher.start()
        self.mock_client = self.mock_client_cls.return_value

        self._models_patcher = patch.object(oci.resource_manager, 'models', MagicMock())
        self._models_patcher.start()

        self._pagination_patcher = patch(
            'oci.pagination.list_call_get_all_results',
            side_effect=lambda fn, *args, **kwargs: fn(*args, **kwargs),
        )
        self._pagination_patcher.start()

    def tearDown(self):
        self._key_patcher.stop()
        self._auth_patcher.stop()
        self._client_patcher.stop()
        self._models_patcher.stop()
        self._pagination_patcher.stop()

    def _cfg(self):
        from oci_bridge.core import ApiKeyConfig
        return ApiKeyConfig(**_VALID_CONFIG)

    def test_create_stack_happy_path(self):
        resp = MagicMock()
        resp.data.id = 'ocid1.stacks.oc1..zzz'
        resp.data.display_name = 'test-stack'
        self.mock_client.create_stack.return_value = resp

        result = create_stack(self._cfg(), 'test-stack', 'ocid1.compartment..w', 'ZIP_UPLOAD', {})

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['stack_id'], 'ocid1.stacks.oc1..zzz')
        self.mock_client.create_stack.assert_called_once()

    def test_create_stack_service_error(self):
        self.mock_client.create_stack.side_effect = ServiceError(404, 'NotFound', {}, 'Not found')

        result = create_stack(self._cfg(), 'name', 'comp', 'ZIP_UPLOAD', {})

        self.assertFalse(result['success'])
        self.assertEqual(result['error']['status'], 404)

    def test_plan_stack_happy_path(self):
        resp = MagicMock()
        resp.data.id = 'ocid1.job..jobid'
        self.mock_client.plan_stack.return_value = resp

        result = job_operation(self._cfg(), 'stack_id', 'PLAN')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['job_id'], 'ocid1.job..jobid')
        self.mock_client.plan_stack.assert_called_once()

    def test_apply_stack_happy_path(self):
        resp = MagicMock()
        resp.data.id = 'ocid1.job..jobid'
        self.mock_client.apply_stack.return_value = resp

        result = job_operation(self._cfg(), 'stack_id', 'APPLY')

        self.assertTrue(result['success'])

    def test_destroy_stack_happy_path(self):
        resp = MagicMock()
        resp.data.id = 'ocid1.job..jobid'
        self.mock_client.destroy_stack.return_value = resp

        result = job_operation(self._cfg(), 'stack_id', 'DESTROY', confirm_destroy=True)

        self.assertTrue(result['success'])

    def test_destroy_without_confirmation(self):
        result = job_operation(self._cfg(), 'stack_id', 'DESTROY')

        self.assertFalse(result['success'])
        self.assertIn('Confirmation required', result['error']['message'])
        # build_authentication should NOT have been called (early return)
        self.mock_client.destroy_stack.assert_not_called()

    def test_get_job_happy_path(self):
        resp = MagicMock()
        resp.data.lifecycle_state = 'SUCCEEDED'
        self.mock_client.get_job.return_value = resp

        result = get_job(self._cfg(), 'job-123')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['status'], 'succeeded')
        self.assertEqual(result['data']['lifecycle_state'], 'SUCCEEDED')
        self.mock_client.get_job.assert_called_once_with(job_id='job-123', retry_strategy=unittest.mock.ANY)

    def test_get_job_in_progress(self):
        resp = MagicMock()
        resp.data.lifecycle_state = 'IN_PROGRESS'
        self.mock_client.get_job.return_value = resp

        result = get_job(self._cfg(), 'job-456')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['status'], 'in_progress')

    def test_get_job_service_error(self):
        self.mock_client.get_job.side_effect = ServiceError(500, 'InternalError', {}, 'err')

        result = get_job(self._cfg(), 'job_id')

        self.assertFalse(result['success'])
        self.assertEqual(result['error']['status'], 500)

    def test_get_job_logs_happy_path(self):
        resp = MagicMock()
        entry1 = MagicMock()
        entry1.message = 'Error: quota exceeded'
        entry2 = MagicMock()
        entry2.message = 'Apply failed'
        resp.data = [entry1, entry2]
        self.mock_client.list_job_logs.return_value = resp

        result = get_job_logs(self._cfg(), 'job-123')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['lines'], ['Error: quota exceeded', 'Apply failed'])

    def test_get_job_logs_filters_empty_messages(self):
        resp = MagicMock()
        entry1 = MagicMock()
        entry1.message = 'real log line'
        entry2 = MagicMock()
        entry2.message = None
        resp.data = [entry1, entry2]
        self.mock_client.list_job_logs.return_value = resp

        result = get_job_logs(self._cfg(), 'job-123')

        self.assertTrue(result['success'])
        self.assertEqual(result['data']['lines'], ['real log line'])

    def test_get_job_logs_service_error(self):
        self.mock_client.list_job_logs.side_effect = ServiceError(404, 'NotFound', {}, 'not found')

        result = get_job_logs(self._cfg(), 'job-123')

        self.assertFalse(result['success'])
        self.assertEqual(result['error']['status'], 404)
