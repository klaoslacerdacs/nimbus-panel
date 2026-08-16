from __future__ import annotations

import os
from dataclasses import dataclass, field
from enum import Enum
from pathlib import Path
from typing import Any, Literal, TypeAlias
from urllib.parse import urlsplit
from urllib.request import getproxies, proxy_bypass

import oci
from cryptography.hazmat.primitives.asymmetric.rsa import RSAPrivateKey


_MAX_PRIVATE_KEY_BYTES = 64 * 1024
_MAX_PASSPHRASE_BYTES = 1024
_CANONICAL_REGIONS = frozenset(oci.regions.REGIONS)
_DEVELOPER_TOOL_CONFIGURATION_LOADED = Path(
    os.environ.get("OCI_DEVELOPER_TOOL_CONFIGURATION_FILE_PATH")
    or "~/.oci/developer-tool-configuration.json"
).expanduser().is_file()
_IMDS_URLS = (
    "http://169.254.169.254/opc/v2",
    "http://[fd00:c1::a9fe:a9fe]/opc/v2",
)


class AuthMode(str, Enum):
    API_KEY = "api_key"
    INSTANCE_PRINCIPAL = "instance_principal"


@dataclass(frozen=True, slots=True)
class ApiKeyConfig:
    tenancy_id: str = field(repr=False)
    user_id: str = field(repr=False)
    fingerprint: str = field(repr=False)
    region: str
    key_content: str = field(repr=False)
    passphrase: str | None = field(default=None, repr=False)
    auth_mode: Literal[AuthMode.API_KEY] = field(
        default=AuthMode.API_KEY, init=False
    )

    def __post_init__(self) -> None:
        if not self.tenancy_id.startswith("ocid1.tenancy."):
            raise ValueError("OCI tenancy ID is invalid")
        if not self.user_id.startswith("ocid1.user."):
            raise ValueError("OCI user ID is invalid")
        if not self.fingerprint.strip():
            raise ValueError("OCI fingerprint cannot be empty")
        if self.region not in _CANONICAL_REGIONS:
            raise ValueError("OCI region is not recognized by the installed SDK")
        _validate_private_key(self.key_content, self.passphrase)


@dataclass(frozen=True, slots=True)
class InstancePrincipalConfig:
    auth_mode: Literal[AuthMode.INSTANCE_PRINCIPAL] = field(
        default=AuthMode.INSTANCE_PRINCIPAL, init=False
    )


OciConfig: TypeAlias = ApiKeyConfig | InstancePrincipalConfig


@dataclass(frozen=True, slots=True)
class OciAuthentication:
    auth_mode: AuthMode
    sdk_config: dict[str, Any] = field(repr=False)
    signer: Any | None = field(repr=False)


@dataclass(frozen=True, slots=True)
class ConnectionError:
    category: Literal["configuration", "network", "service", "unexpected"]
    exception_type: str
    status: int | None = None
    code: str | None = None
    request_id: str | None = None


@dataclass(frozen=True, slots=True)
class ConnectionResult:
    ok: bool
    auth_mode: AuthMode
    operation: Literal["get_tenancy", "list_regions"]
    error: ConnectionError | None = None


def build_authentication(config: OciConfig) -> OciAuthentication:
    _reject_developer_tool_configuration()

    if isinstance(config, ApiKeyConfig):
        sdk_config = {
            "tenancy": config.tenancy_id,
            "user": config.user_id,
            "fingerprint": config.fingerprint,
            "region": config.region,
            "key_content": config.key_content,
        }
        if config.passphrase is not None:
            sdk_config["pass_phrase"] = config.passphrase
        oci.config.validate_config(sdk_config)
        return OciAuthentication(config.auth_mode, sdk_config, None)

    _validate_instance_principal_environment()

    signer = oci.auth.signers.InstancePrincipalsSecurityTokenSigner()
    return OciAuthentication(config.auth_mode, {}, signer)


def validate_connection(config: OciConfig) -> ConnectionResult:
    operation: Literal["get_tenancy", "list_regions"] = (
        "get_tenancy" if isinstance(config, ApiKeyConfig) else "list_regions"
    )

    try:
        authentication = build_authentication(config)
        client = oci.identity.IdentityClient(
            authentication.sdk_config,
            **(
                {"signer": authentication.signer}
                if authentication.signer is not None
                else {}
            ),
        )
        retry_strategy = oci.retry.NoneRetryStrategy()

        if isinstance(config, ApiKeyConfig):
            client.get_tenancy(
                authentication.sdk_config["tenancy"],
                retry_strategy=retry_strategy,
            )
        else:
            client.list_regions(retry_strategy=retry_strategy)
    except Exception as error:
        return ConnectionResult(
            ok=False,
            auth_mode=config.auth_mode,
            operation=operation,
            error=_normalize_error(error),
        )

    return ConnectionResult(
        ok=True,
        auth_mode=config.auth_mode,
        operation=operation,
    )


def _normalize_error(error: Exception) -> ConnectionError:
    if isinstance(error, oci.exceptions.ServiceError):
        return ConnectionError(
            category="service",
            exception_type=type(error).__name__,
            status=error.status,
            code=error.code,
            request_id=error.request_id,
        )

    if isinstance(
        error,
        (
            oci.exceptions.ConfigFileNotFound,
            oci.exceptions.InvalidConfig,
            oci.exceptions.InvalidPrivateKey,
            oci.exceptions.MissingPrivateKeyPassphrase,
            ValueError,
        ),
    ):
        category: Literal["configuration", "network", "service", "unexpected"] = (
            "configuration"
        )
    elif isinstance(error, oci.exceptions.RequestException):
        category = "network"
    else:
        category = "unexpected"

    return ConnectionError(category=category, exception_type=type(error).__name__)


def _validate_private_key(key_content: str, passphrase: str | None) -> None:
    if not isinstance(key_content, str):
        raise ValueError("OCI private key content is invalid")

    try:
        encoded_key = key_content.encode("ascii")
    except UnicodeEncodeError:
        raise ValueError("OCI private key content is invalid") from None

    if (
        not encoded_key
        or len(encoded_key) > _MAX_PRIVATE_KEY_BYTES
        or b"\x00" in encoded_key
    ):
        raise ValueError("OCI private key content is invalid")

    if passphrase is not None:
        if not isinstance(passphrase, str):
            raise ValueError("OCI private key passphrase is invalid")
        try:
            encoded_passphrase = passphrase.encode("ascii")
        except UnicodeEncodeError:
            raise ValueError("OCI private key passphrase is invalid") from None
        if (
            len(encoded_passphrase) > _MAX_PASSPHRASE_BYTES
            or b"\x00" in encoded_passphrase
        ):
            raise ValueError("OCI private key passphrase is invalid")

    try:
        private_key = oci.signer.load_private_key(key_content, passphrase)
    except Exception:
        raise ValueError("OCI private key or passphrase is invalid") from None

    if not isinstance(private_key, RSAPrivateKey):
        raise ValueError("OCI private key must be an RSA key")
    if private_key.key_size < 2048:
        raise ValueError("OCI private key must be at least 2048 bits")


def _reject_developer_tool_configuration() -> None:
    if _DEVELOPER_TOOL_CONFIGURATION_LOADED:
        raise ValueError("OCI developer tool endpoint overrides are not allowed")


def _validate_instance_principal_environment() -> None:
    if "OCI_METADATA_BASE_URL" in os.environ:
        raise ValueError("OCI metadata endpoint overrides are not allowed")

    proxies = getproxies()
    for metadata_url in _IMDS_URLS:
        metadata_host = urlsplit(metadata_url).hostname
        if (
            metadata_host
            and not proxy_bypass(metadata_host)
            and (proxies.get("http") or proxies.get("all"))
        ):
            raise ValueError("OCI metadata requests must bypass HTTP proxies")
