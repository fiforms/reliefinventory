"""Key-based pairing and authentication for this device (rstation/FEDERATION_AUTH.md), done entirely in the
product layer: nothing in the platform (`rstation/platform`, the unmodified slideannouncer submodule) changes.

The platform's pairing code and heartbeat know one thing about authentication: they read `/data/device-token`
and send its contents as `Authorization: Bearer <contents>` (pairing.py, heartbeat.py, system/scripts/rauc-update.py,
and this product's own print_api.py). So the device keeps a credential **signed by its own key** in that file:

    RIK-<base64url uuid>.<issued-at>.<expires-at>.<signature>

signature = Ed25519 over `"ri-bearer-v1\\n" uuid "\\n" server host "\\n" issued-at "\\n" expires-at`. The server holds only
the public key, accepts a credential for its own host only, and answers an expired one with 419 (never 401 — the
platform wipes its pairing on any 401). `maintain()` mints a fresh credential every few hours, and again if the
clock jumps, so a Pi that boots with a wrong clock repairs itself once NTP has set it. The private key lives in
`/data/federation-key`, outside the platform's wipe list, so un-pairing forgets the server but not the identity.

Pairing (`federated_pair`) replaces the platform's `pairing.pair` — `install()` swaps the module attribute, which
`main.py` calls as `pairing.pair(...)`. Against a server that predates federation it falls back to the platform's own.

A device paired before federation carries a legacy bearer token; while the server still honours those
(RI_FEDERATION_LEGACY_DEVICES) `refresh_once()` trades it for a key-based trust (`POST /upgrade-device`), so an
over-the-air update of this product moves a headless fleet across without anyone re-pairing it.

Ed25519 signing is implemented below (RFC 8032, section 6) because the image's virtualenv is built into the OS image
and declares no crypto library; `cryptography` is used instead when it happens to be installed. It signs a handful
of short messages per day and never on request from outside, so the pure-Python version's timing behaviour is not
exposed to anyone who could measure it.
"""
import asyncio
import base64
import hashlib
import logging
import os
import time
from pathlib import Path
from urllib.parse import urlsplit

import httpx

log = logging.getLogger(__name__)

KEY_FILE = Path("/data/federation-key")
KIND = "rstation"
API_PREFIX = "/api/federation/v1"
CREDENTIAL_SECONDS = 14 * 86400
# A credential older than this is replaced (and one issued "in the future" is too: the clock was wrong then).
REFRESH_AFTER_SECONDS = 6 * 3600
CHECK_INTERVAL_SECONDS = 300
UPGRADE_RETRY_SECONDS = 3600

try:  # preferred when present
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
except Exception:  # pragma: no cover - depends on the image
    Ed25519PrivateKey = None
use_library = Ed25519PrivateKey is not None


# ---- encoding -------------------------------------------------------------------------------------------

def b64(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def unb64(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))


# ---- Ed25519 (RFC 8032 section 6, extended coordinates) ---------------------------------------------------

_P = 2**255 - 19
_Q = 2**252 + 27742317777372353535851937790883648493
_D = -121665 * pow(121666, _P - 2, _P) % _P
_SQRT_M1 = pow(2, (_P - 1) // 4, _P)


def _inv(x: int) -> int:
    return pow(x, _P - 2, _P)


def _recover_x(y: int, sign: int) -> int:
    x2 = (y * y - 1) * _inv(_D * y * y + 1)
    x = pow(x2, (_P + 3) // 8, _P)
    if (x * x - x2) % _P != 0:
        x = x * _SQRT_M1 % _P
    if (x & 1) != sign:
        x = _P - x
    return x


_GY = 4 * _inv(5) % _P
_GX = _recover_x(_GY, 0)
_G = (_GX, _GY, 1, _GX * _GY % _P)


def _add(a, b):
    A, B = (a[1] - a[0]) * (b[1] - b[0]) % _P, (a[1] + a[0]) * (b[1] + b[0]) % _P
    C, D = 2 * a[3] * b[3] * _D % _P, 2 * a[2] * b[2] % _P
    E, F, G, H = B - A, D - C, D + C, B + A
    return (E * F % _P, G * H % _P, F * G % _P, E * H % _P)


def _mul(s: int, point):
    q = (0, 1, 1, 0)
    while s > 0:
        if s & 1:
            q = _add(q, point)
        point = _add(point, point)
        s >>= 1
    return q


def _compress(point) -> bytes:
    zinv = _inv(point[2])
    x, y = point[0] * zinv % _P, point[1] * zinv % _P
    return int.to_bytes(y | ((x & 1) << 255), 32, "little")


def _expand(seed: bytes):
    h = hashlib.sha512(seed).digest()
    a = int.from_bytes(h[:32], "little")
    a &= (1 << 254) - 8
    a |= 1 << 254
    return a, h[32:]


def _modq(data: bytes) -> int:
    return int.from_bytes(hashlib.sha512(data).digest(), "little") % _Q


def _pure_public(seed: bytes) -> bytes:
    return _compress(_mul(_expand(seed)[0], _G))


def _pure_sign(seed: bytes, message: bytes) -> bytes:
    a, prefix = _expand(seed)
    public = _compress(_mul(a, _G))
    r = _modq(prefix + message)
    big_r = _compress(_mul(r, _G))
    s = (r + _modq(big_r + public + message) * a) % _Q
    return big_r + int.to_bytes(s, 32, "little")


def public_key_bytes(seed: bytes) -> bytes:
    if use_library:
        return Ed25519PrivateKey.from_private_bytes(seed).public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    return _pure_public(seed)


def sign_bytes(seed: bytes, message: bytes) -> bytes:
    if use_library:
        return Ed25519PrivateKey.from_private_bytes(seed).sign(message)
    return _pure_sign(seed, message)


# ---- the key ----------------------------------------------------------------------------------------------

def load_or_create_seed() -> bytes:
    """This device's 32-byte private key, generated on first use and kept (0600) across un-pairing."""
    try:
        seed = unb64(KEY_FILE.read_text().strip())
        if len(seed) == 32:
            return seed
    except (OSError, ValueError):
        pass
    seed = os.urandom(32)
    KEY_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = KEY_FILE.with_name(KEY_FILE.name + ".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        handle.write(b64(seed))
    os.replace(tmp, KEY_FILE)
    return seed


def public_key(seed: bytes) -> str:
    return b64(public_key_bytes(seed))


def sign(seed: bytes, message: str) -> str:
    return b64(sign_bytes(seed, message.encode()))


# ---- messages (identical to App\Services\Federation; tests/Fixtures/federation-signing-vectors.json) --------

def pairing_message(code: str, pub: str, uuid: str) -> str:
    normalized = "".join(ch for ch in code if ch.isascii() and ch.isalnum()).upper()
    return f"ri-pair-v1\n{normalized}\n{pub}\n{uuid}"


def upgrade_message(uuid: str, pub: str) -> str:
    return f"ri-upgrade-v1\n{uuid}\n{pub}"


def bearer_message(uuid: str, host: str, issued_at: int, expires_at: int) -> str:
    return f"ri-bearer-v1\n{uuid}\n{host.lower()}\n{issued_at}\n{expires_at}"


def mint_credential(seed: bytes, uuid: str, host: str, issued_at: int, expires_at: int) -> str:
    signature = sign(seed, bearer_message(uuid, host, issued_at, expires_at))
    return f"RIK-{b64(uuid.encode())}.{issued_at}.{expires_at}.{signature}"


def credential_times(token: str) -> tuple[int, int] | None:
    """(issued-at, expires-at) of one of our credentials, or None for anything else (e.g. a legacy bearer token)."""
    if not token.startswith("RIK-"):
        return None
    parts = token[4:].split(".")
    if len(parts) != 4 or not (parts[1].isdigit() and parts[2].isdigit()):
        return None
    return int(parts[1]), int(parts[2])


def server_host(server_url: str) -> str:
    """The Host the server sees for this URL: lower-case, port only when it is not the scheme's default."""
    parts = urlsplit(server_url)
    host = (parts.hostname or "").lower()
    if ":" in host:
        host = f"[{host}]"
    default = {"https": 443, "http": 80}.get(parts.scheme)
    return f"{host}:{parts.port}" if parts.port and parts.port != default else host


# ---- the credential file ----------------------------------------------------------------------------------

def write_credential(token: str, keep_mtime: float | None = None) -> None:
    """Replace /data/device-token atomically. Its mtime is the platform's "paired since", so a refresh keeps it."""
    import pairing  # the platform's pairing module

    path = pairing.DEVICE_TOKEN_FILE
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(token)
    tmp.chmod(0o640)
    if keep_mtime is not None:
        os.utime(tmp, (keep_mtime, keep_mtime))
    os.replace(tmp, path)


# ---- pairing ----------------------------------------------------------------------------------------------

async def federated_pair(code: str, device_name: str, transport: httpx.AsyncBaseTransport | None = None) -> dict:
    """Replacement for the platform's `pairing.pair`: send this device's public key with the pairing code and a
    proof that it holds the key, then do what the platform does with the answer (minus the bearer token)."""
    import identity
    import pairing

    uuid = identity.get_device_uuid()
    if not uuid:
        raise pairing.PairingError("This device's identity isn't ready yet. Wait a minute and try again.")
    server = pairing.read_server_url()
    seed = load_or_create_seed()
    pub = public_key(seed)
    payload = {
        "code": code,
        "kind": KIND,
        "uuid": uuid,
        "name": device_name,
        "public_key": pub,
        "signature": sign(seed, pairing_message(code, pub, uuid)),
        "mac_address": identity.get_mac_address(),
    }
    language_hint = pairing.read_local_language() or pairing.read_language_boot_hint()
    if language_hint:
        payload["language"] = language_hint

    try:
        async with httpx.AsyncClient(timeout=10, transport=transport) as client:
            resp = await client.post(f"{server}{API_PREFIX}/pair", json=payload)
    except httpx.RequestError as exc:
        raise pairing.PairingError(f"Could not reach the server: {exc}") from exc

    if resp.status_code in (404, 405) and _platform_pair is not None:
        # A server from before federation: it still pairs the old way.
        return await _platform_pair(code, device_name)
    if resp.status_code == 422:
        raise pairing.PairingError("Invalid or expired pairing code.")
    if resp.status_code == 429:
        raise pairing.PairingError("Too many pairing attempts — wait a few minutes and try again.")
    if resp.status_code >= 400:
        raise pairing.PairingError(f"Pairing failed (server said HTTP {resp.status_code}).")

    data = resp.json()
    now = int(time.time())
    write_credential(mint_credential(seed, uuid, server_host(server), now, now + CREDENTIAL_SECONDS))

    # The rest is what the platform's pair() does after it has a token.
    pairing.write_device_name(device_name)
    if data.get("entity_name"):
        pairing.write_entity_name(data["entity_name"])
    pairing.apply_server_language(data.get("language"), data.get("language_revision"))
    slug = pairing.slugify_hostname(device_name) or pairing._fallback_hostname()
    taken = set(data.get("sibling_hostnames") or [])
    hostname, suffix = slug, 2
    while hostname in taken:
        hostname = f"{slug}-{suffix}"
        suffix += 1
    pairing.write_hostname(hostname)

    return data


_platform_pair = None
_last_upgrade_attempt = 0.0


def install() -> None:
    """Make the platform's pairing screen use `federated_pair`. Safe to call more than once."""
    global _platform_pair
    try:
        import pairing
    except ImportError:  # unit tests of other modules run without the platform
        return
    if getattr(pairing.pair, "_federated", False):
        return
    _platform_pair = pairing.pair
    federated_pair._federated = True
    pairing.pair = federated_pair


# ---- keeping the credential fresh -------------------------------------------------------------------------

async def refresh_once(now: float | None = None, transport: httpx.AsyncBaseTransport | None = None) -> str:
    """One look at the credential file. Returns what happened: unpaired | fresh | refreshed | upgraded | waiting."""
    global _last_upgrade_attempt
    import identity
    import pairing

    token = pairing.read_device_token()
    if not token:
        return "unpaired"
    uuid = identity.get_device_uuid()
    try:
        server = pairing.read_server_url()
    except pairing.PairingError:
        return "waiting"
    if not uuid:
        return "waiting"
    now = int(now if now is not None else time.time())
    seed = load_or_create_seed()
    mtime = pairing.DEVICE_TOKEN_FILE.stat().st_mtime

    times = credential_times(token)
    if times is not None:
        # Fresh: issued recently (by a clock that agrees with ours) and good for at least another refresh interval.
        if 0 <= now - times[0] < REFRESH_AFTER_SECONDS and times[1] - now > REFRESH_AFTER_SECONDS:
            return "fresh"
        write_credential(mint_credential(seed, uuid, server_host(server), now, now + CREDENTIAL_SECONDS), keep_mtime=mtime)
        return "refreshed"

    # A token from before federation: trade it for a trust, if the server still honours it.
    if now - _last_upgrade_attempt < UPGRADE_RETRY_SECONDS:
        return "waiting"
    _last_upgrade_attempt = now
    pub = public_key(seed)
    body = {"public_key": pub, "signature": sign(seed, upgrade_message(uuid, pub))}
    try:
        async with httpx.AsyncClient(timeout=10, transport=transport) as client:
            resp = await client.post(f"{server}{API_PREFIX}/upgrade-device", json=body, headers={"Authorization": f"Bearer {token}"})
    except httpx.RequestError as exc:
        log.info("could not reach the server to upgrade this device's pairing: %s", exc)
        return "waiting"
    if resp.status_code != 201:
        log.info("the server did not upgrade this device's pairing (HTTP %s)", resp.status_code)
        return "waiting"
    write_credential(mint_credential(seed, uuid, server_host(server), now, now + CREDENTIAL_SECONDS), keep_mtime=mtime)
    return "upgraded"


async def maintain() -> None:
    """Background task: keep a fresh credential in place for the platform to send. Never raises."""
    while True:
        try:
            await refresh_once()
        except Exception as exc:  # a bug here must never take the backend down
            log.warning("credential refresh failed: %s", exc)
        await asyncio.sleep(CHECK_INTERVAL_SECONDS)
