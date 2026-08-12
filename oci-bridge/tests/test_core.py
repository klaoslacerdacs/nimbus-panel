import os
import unittest
from functools import lru_cache
from unittest.mock import ANY, MagicMock, patch

import oci
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from oci_bridge import (
    ApiKeyConfig,
    AuthMode,
    InstancePrincipalConfig,
    build_authentication,
    validate_connection,
)


@lru_cache(maxsize=4)
def private_key(passphrase: str | None = None, key_size: int = 2048) -> str:
    key = rsa.generate_private_key(public_exponent=65537, key_size=key_size)
    encryption = (
        serialization.BestAvailableEncryption(passphrase.encode("ascii"))
        if passphrase is not None
        else serialization.NoEncryption()
    )

    return key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=encryption,
    ).decode("ascii")


def api_key_config(**overrides):
    values = {
        "tenancy_id": "ocid1.tenancy.oc1..example",
        "user_id": "ocid1.user.oc1..example",
        "fingerprint": "aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99",
        "region": "us-phoenix-1",
        "key_content": private_key(),
    }
    return ApiKeyConfig(**(values | overrides))


class AuthenticationTests(unittest.TestCase):
    @patch("oci_bridge.core.oci.config.validate_config")
    def test_builds_api_key_config_in_memory(self, validate_config):
        config = api_key_config()

        authentication = build_authentication(config)

        validate_config.assert_called_once_with(authentication.sdk_config)
        self.assertEqual(authentication.auth_mode, AuthMode.API_KEY)
        self.assertEqual(
            authentication.sdk_config,
            {
                "tenancy": "ocid1.tenancy.oc1..example",
                "user": "ocid1.user.oc1..example",
                "fingerprint": "aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99",
                "region": "us-phoenix-1",
                "key_content": private_key(),
            },
        )
        self.assertIsNone(authentication.signer)
        self.assertNotIn("private-secret", repr(config))
        self.assertNotIn("private-secret", repr(authentication))

    @patch("oci_bridge.core.oci.config.validate_config")
    def test_builds_encrypted_api_key_config_with_redacted_passphrase(
        self, validate_config
    ):
        passphrase = "private-secret-passphrase"
        config = api_key_config(
            key_content=private_key(passphrase),
            passphrase=passphrase,
        )

        authentication = build_authentication(config)

        validate_config.assert_called_once_with(authentication.sdk_config)
        self.assertEqual(authentication.sdk_config["pass_phrase"], passphrase)
        self.assertNotIn(passphrase, repr(config))
        self.assertNotIn(passphrase, repr(authentication))

    @patch(
        "oci_bridge.core.oci.auth.signers.InstancePrincipalsSecurityTokenSigner"
    )
    def test_builds_instance_principal_signer(self, signer_factory):
        signer = signer_factory.return_value
        signer.region = "us-phoenix-1"

        authentication = build_authentication(InstancePrincipalConfig())

        signer_factory.assert_called_once_with()
        self.assertEqual(authentication.auth_mode, AuthMode.INSTANCE_PRINCIPAL)
        self.assertEqual(authentication.sdk_config, {})
        self.assertIs(authentication.signer, signer)

    def test_rejects_metadata_endpoint_override(self):
        with patch.dict(
            os.environ,
            {"OCI_METADATA_BASE_URL": "https://attacker.invalid"},
        ):
            with self.assertRaisesRegex(ValueError, "overrides are not allowed"):
                build_authentication(InstancePrincipalConfig())

    def test_rejects_http_proxy_for_instance_metadata(self):
        with patch.dict(os.environ, {"HTTP_PROXY": "http://proxy.invalid"}, clear=True):
            with self.assertRaisesRegex(ValueError, "bypass HTTP proxies"):
                build_authentication(InstancePrincipalConfig())

    def test_allows_http_proxy_when_instance_metadata_bypasses_it(self):
        with patch.dict(
            os.environ,
            {
                "HTTP_PROXY": "http://proxy.invalid",
                "NO_PROXY": "169.254.169.254,fd00:c1::a9fe:a9fe",
            },
            clear=True,
        ):
            with patch(
                "oci_bridge.core.oci.auth.signers.InstancePrincipalsSecurityTokenSigner"
            ) as signer_factory:
                signer_factory.return_value.region = "us-phoenix-1"

                build_authentication(InstancePrincipalConfig())

                signer_factory.assert_called_once_with()

    def test_rejects_developer_tool_endpoint_overrides(self):
        with patch("oci_bridge.core._DEVELOPER_TOOL_CONFIGURATION_LOADED", True):
            with self.assertRaisesRegex(ValueError, "overrides are not allowed"):
                build_authentication(api_key_config())

    def test_rejects_endpoint_and_extra_fields(self):
        with self.assertRaises(TypeError):
            api_key_config(service_endpoint="https://attacker.invalid")
        with self.assertRaises(TypeError):
            api_key_config(hostname="attacker.invalid")

    def test_rejects_invalid_auth_combinations(self):
        with self.assertRaises(TypeError):
            InstancePrincipalConfig(region="us-phoenix-1")
        with self.assertRaises(TypeError):
            api_key_config(auth_mode=AuthMode.INSTANCE_PRINCIPAL)

    def test_rejects_unknown_region(self):
        with self.assertRaisesRegex(ValueError, "region is not recognized"):
            api_key_config(region="https://identity.example.com")

    def test_rejects_invalid_private_key_without_leaking_it(self):
        secret = "-----BEGIN PRIVATE KEY-----\nprivate-secret"

        with self.assertRaises(ValueError) as raised:
            api_key_config(key_content=secret)

        self.assertNotIn("private-secret", str(raised.exception))

    def test_rejects_wrong_passphrase_without_leaking_it(self):
        passphrase = "private-secret-passphrase"

        with self.assertRaises(ValueError) as raised:
            api_key_config(
                key_content=private_key(passphrase),
                passphrase="wrong-private-secret-passphrase",
            )

        self.assertNotIn("private-secret", str(raised.exception))

    def test_rejects_rsa_keys_smaller_than_2048_bits(self):
        with self.assertRaisesRegex(ValueError, "at least 2048 bits"):
            api_key_config(key_content=private_key(key_size=1024))


class ConnectionTests(unittest.TestCase):
    @patch("oci_bridge.core.oci.identity.IdentityClient")
    @patch("oci_bridge.core.build_authentication")
    def test_validates_api_key_with_tenancy_lookup(self, build, client_factory):
        from oci_bridge.core import OciAuthentication

        sdk_config = {"tenancy": "ocid1.tenancy.oc1..example"}
        build.return_value = OciAuthentication(AuthMode.API_KEY, sdk_config, None)
        client = client_factory.return_value
        client.get_tenancy.return_value.data = {"secret": "private-secret"}

        result = validate_connection(api_key_config())

        client_factory.assert_called_once_with(sdk_config)
        client.get_tenancy.assert_called_once_with(
            "ocid1.tenancy.oc1..example", retry_strategy=ANY
        )
        self.assertTrue(result.ok)
        self.assertEqual(result.operation, "get_tenancy")
        self.assertIsNone(result.error)
        self.assertNotIn("private-secret", repr(result))

    @patch("oci_bridge.core.oci.identity.IdentityClient")
    @patch("oci_bridge.core.build_authentication")
    def test_validates_instance_principal_with_region_lookup(
        self, build, client_factory
    ):
        from oci_bridge.core import OciAuthentication

        signer = MagicMock()
        build.return_value = OciAuthentication(
            AuthMode.INSTANCE_PRINCIPAL, {}, signer
        )
        client = client_factory.return_value

        result = validate_connection(InstancePrincipalConfig())

        client_factory.assert_called_once_with({}, signer=signer)
        client.list_regions.assert_called_once_with(retry_strategy=ANY)
        self.assertTrue(result.ok)
        self.assertEqual(result.operation, "list_regions")

    @patch("oci_bridge.core.oci.identity.IdentityClient")
    @patch("oci_bridge.core.build_authentication")
    def test_normalizes_service_error_without_message(self, build, client_factory):
        from oci_bridge.core import OciAuthentication

        sdk_config = {"tenancy": "ocid1.tenancy.oc1..example"}
        build.return_value = OciAuthentication(AuthMode.API_KEY, sdk_config, None)
        client_factory.return_value.get_tenancy.side_effect = (
            oci.exceptions.ServiceError(
                401,
                "NotAuthenticated",
                {"opc-request-id": "request-id"},
                "private-secret must not leak",
            )
        )

        result = validate_connection(api_key_config())

        self.assertFalse(result.ok)
        self.assertEqual(result.error.category, "service")
        self.assertEqual(result.error.status, 401)
        self.assertEqual(result.error.code, "NotAuthenticated")
        self.assertEqual(result.error.request_id, "request-id")
        self.assertNotIn("private-secret", repr(result))

    @patch("oci_bridge.core.build_authentication")
    def test_normalizes_config_error_without_message(self, build):
        build.side_effect = ValueError("private-secret must not leak")

        result = validate_connection(api_key_config())

        self.assertFalse(result.ok)
        self.assertEqual(result.error.category, "configuration")
        self.assertEqual(result.error.exception_type, "ValueError")
        self.assertNotIn("private-secret", repr(result))


if __name__ == "__main__":
    unittest.main()
