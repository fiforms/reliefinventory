import asyncio
import email.utils
import json
import os
from pathlib import Path

import httpx
import pytest

from . import federation

run = asyncio.run

SERVER = "https://ri.example.org"
UUID = "c1a2b3d4-0000-4000-8000-000000000001"


def _fixture():
    """tests/Fixtures/federation-signing-vectors.json — the file the PHP server and the Rust client also test against."""
    candidates = []
    if os.environ.get("PLATFORM_ROOT"):
        candidates.append(Path(os.environ["PLATFORM_ROOT"]).parent.parent)  # <repo>/rstation/platform -> <repo>
    candidates.append(Path(__file__).resolve().parents[3])
    for root in candidates:
        path = root / "tests" / "Fixtures" / "federation-signing-vectors.json"
        if path.exists():
            return json.loads(path.read_text())
    pytest.skip("the shared signing-vector fixture isn't reachable from this checkout")


@pytest.fixture(params=["library", "pure"])
def implementation(request, monkeypatch):
    """Every signing test runs on the `cryptography` path (when installed) and on the dependency-free one."""
    if request.param == "library" and federation.Ed25519PrivateKey is None:
        pytest.skip("cryptography is not installed")
    monkeypatch.setattr(federation, "use_library", request.param == "library")
    return request.param


@pytest.fixture
def device(tmp_path, monkeypatch):
    """The platform's pairing/identity modules pointed at a scratch /data, with a paired-looking server."""
    import identity
    import pairing

    for name in ("DEVICE_TOKEN_FILE", "DEVICE_NAME_FILE", "HOSTNAME_FILE", "ENTITY_NAME_FILE", "LANGUAGE_FILE",
                 "LANGUAGE_REVISION_FILE", "LANGUAGE_PENDING_FILE", "LOCAL_LANGUAGE_FILE", "LANGUAGE_BOOT_HINT_FILE"):
        monkeypatch.setattr(pairing, name, tmp_path / name.lower())
    monkeypatch.setattr(federation, "KEY_FILE", tmp_path / "federation-key")
    monkeypatch.setattr(pairing, "read_server_url", lambda: SERVER)
    monkeypatch.setattr(identity, "get_device_uuid", lambda: UUID)
    monkeypatch.setattr(identity, "get_mac_address", lambda: "aa:bb:cc:dd:ee:ff")
    return pairing


# ---- Ed25519 ---------------------------------------------------------------------------------------------

RFC8032 = [  # RFC 8032 section 7.1, tests 1 and 2
    ("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60",
     "d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a", "",
     "e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b"),
    ("4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb",
     "3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c", "72",
     "92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00"),
]


@pytest.mark.parametrize("seed, public, message, signature", RFC8032)
def test_ed25519_matches_the_rfc_8032_vectors(implementation, seed, public, message, signature):
    assert federation.public_key_bytes(bytes.fromhex(seed)).hex() == public
    assert federation.sign_bytes(bytes.fromhex(seed), bytes.fromhex(message)).hex() == signature


def test_the_shared_vectors_reproduce(implementation):
    fixture = _fixture()
    seed = federation.unb64(fixture["seed_b64"])
    assert federation.public_key(seed) == fixture["public_key"]

    for v in fixture["vectors"]:
        canonical = "\n".join([v["method"].upper(), v["path_and_query"], v["host"].lower(), str(v["timestamp"]), v["nonce"],
                               __import__("hashlib").sha256(v["body"].encode()).hexdigest()])
        assert canonical == v["canonical"]
        assert federation.sign(seed, canonical) == v["signature"]
    for v in fixture["pairing_vectors"]:
        assert federation.pairing_message(v["code"], fixture["public_key"], v["uuid"]) == v["message"]
        assert federation.sign(seed, v["message"]) == v["signature"]
    for v in fixture["bearer_vectors"]:
        assert federation.bearer_message(v["uuid"], v["host"], v["iat"], v["exp"]) == v["message"]
        assert federation.mint_credential(seed, v["uuid"], v["host"], v["iat"], v["exp"]) == v["token"]
        assert federation.credential_times(v["token"]) == (v["iat"], v["exp"])


def test_the_two_implementations_agree_on_fresh_keys(monkeypatch):
    if federation.Ed25519PrivateKey is None:
        pytest.skip("cryptography is not installed")
    for _ in range(3):
        seed = os.urandom(32)
        assert federation._pure_public(seed) == federation.public_key_bytes(seed)
        assert federation._pure_sign(seed, b"message") == federation.sign_bytes(seed, b"message")


@pytest.mark.parametrize("url, host", [
    ("https://ri.example.org", "ri.example.org"), ("https://RI.Example.org:443/app", "ri.example.org"),
    ("http://10.0.0.5:8000", "10.0.0.5:8000"), ("http://10.0.0.5:80", "10.0.0.5"), ("https://[::1]:8443", "[::1]:8443"),
])
def test_server_host_is_what_the_server_sees(url, host):
    assert federation.server_host(url) == host


@pytest.mark.parametrize("url, audience", [
    ("https://ri.example.org", "ri.example.org"), ("https://ri.example.org/app", "ri.example.org"),
    ("https://ri.example.org/w/K7Q2X9MD", "ri.example.org/w/K7Q2X9MD"), ("http://10.0.0.5:8080/w/k7q2x9md/", "10.0.0.5:8080/w/k7q2x9md"),
    ("https://ri.example.org/w/short", "ri.example.org"),
])
def test_server_audience_includes_a_warehouse_prefix(url, audience):
    assert federation.server_audience(url) == audience


def test_credential_times_ignores_anything_else():
    assert federation.credential_times("a-legacy-random-token") is None
    assert federation.credential_times("RIK-x.1.2") is None
    assert federation.credential_times("RIK-x.a.b.sig") is None


def test_the_key_is_created_once_and_kept_private(device):
    first = federation.load_or_create_seed()
    assert federation.load_or_create_seed() == first and len(first) == 32
    assert oct(federation.KEY_FILE.stat().st_mode & 0o777) == "0o600"
    federation.KEY_FILE.write_text("not a key")
    assert federation.load_or_create_seed() != first  # a damaged file is replaced rather than trusted


# ---- pairing -----------------------------------------------------------------------------------------------

def _server(handler):
    return httpx.MockTransport(handler)


def _pair_ok(request):
    return httpx.Response(201, json={
        "server": {"public_key": "SERVERKEY"}, "device_id": 7, "entity_name": "Station Org",
        "sibling_hostnames": ["dock"], "language": "es", "language_revision": 3,
    })


def test_pairing_sends_the_key_with_a_proof_and_stores_a_credential_not_a_token(device, implementation):
    seen = []

    def handler(request):
        seen.append((str(request.url), json.loads(request.content)))
        return _pair_ok(request)

    data = run(federation.federated_pair("abcde-fghjk", "Dock", transport=_server(handler)))

    url, body = seen[0]
    assert url == f"{SERVER}/api/federation/v1/pair"
    assert body["kind"] == "rstation" and body["uuid"] == UUID and body["name"] == "Dock"
    assert body["public_key"] == federation.public_key(federation.load_or_create_seed())
    assert body["signature"] == federation.sign(federation.load_or_create_seed(), federation.pairing_message("ABCDE-FGHJK", body["public_key"], UUID))
    assert data["device_id"] == 7

    token = device.DEVICE_TOKEN_FILE.read_text()
    iat, exp = federation.credential_times(token)
    assert exp - iat == federation.CREDENTIAL_SECONDS
    assert token == federation.mint_credential(federation.load_or_create_seed(), UUID, "ri.example.org", iat, exp)
    assert oct(device.DEVICE_TOKEN_FILE.stat().st_mode & 0o777) == "0o640"
    # what the platform does with a successful pairing
    assert device.read_device_name() == "Dock"
    assert device.ENTITY_NAME_FILE.read_text() == "Station Org"
    assert device.read_hostname() == "dock-2"  # "dock" was taken by a sibling
    assert device.LANGUAGE_REVISION_FILE.read_text() == "3"


@pytest.mark.parametrize("status, message", [(422, "Invalid or expired"), (429, "Too many"), (500, "HTTP 500")])
def test_pairing_errors_read_the_way_the_platforms_do(device, status, message):
    import pairing

    with pytest.raises(pairing.PairingError, match=message):
        run(federation.federated_pair("ABCDE-FGHJK", "Dock", transport=_server(lambda r: httpx.Response(status, json={}))))
    assert not device.DEVICE_TOKEN_FILE.exists()


def test_an_unreachable_server_is_a_pairing_error(device):
    import pairing

    def refuse(request):
        raise httpx.ConnectError("no route", request=request)

    with pytest.raises(pairing.PairingError, match="Could not reach the server"):
        run(federation.federated_pair("ABCDE-FGHJK", "Dock", transport=_server(refuse)))


def test_a_server_from_before_federation_is_paired_the_old_way(device, monkeypatch):
    used = []

    async def platform_pair(code, name):
        used.append((code, name))
        return {"device_id": 1}

    monkeypatch.setattr(federation, "_platform_pair", platform_pair)
    result = run(federation.federated_pair("123456", "Dock", transport=_server(lambda r: httpx.Response(404))))

    assert used == [("123456", "Dock")] and result == {"device_id": 1}


def test_importing_the_product_swaps_in_federated_pairing_over_the_platforms_signature(device):
    import inspect

    import pairing

    # `from products.rstation import product` ran install(): main.py's `pairing.pair(code, device_name)` is ours now,
    # and what it replaced still has the two-argument shape this module falls back to.
    assert pairing.pair is federation.federated_pair
    assert list(inspect.signature(federation._platform_pair).parameters)[:2] == ["code", "device_name"]
    assert federation._platform_pair is not federation.federated_pair


def test_install_is_idempotent(device, monkeypatch):
    import pairing

    async def platform_pair(code, device_name):
        return {}

    monkeypatch.setattr(pairing, "pair", platform_pair)
    monkeypatch.setattr(federation, "_platform_pair", None)
    federation.install()
    assert pairing.pair is federation.federated_pair and federation._platform_pair is platform_pair
    federation.install()
    assert federation._platform_pair is platform_pair


# ---- keeping the credential fresh ---------------------------------------------------------------------------

def _credential(device, issued, name_seed=None):
    seed = federation.load_or_create_seed()
    token = federation.mint_credential(seed, UUID, "ri.example.org", issued, issued + federation.CREDENTIAL_SECONDS)
    federation.write_credential(token)
    os.utime(device.DEVICE_TOKEN_FILE, (1_700_000_000, 1_700_000_000))
    return token


def test_nothing_happens_while_unpaired(device):
    assert run(federation.refresh_once()) == "unpaired"


def test_a_recent_credential_is_left_alone(device):
    token = _credential(device, 1_790_000_000)
    assert run(federation.refresh_once(now=1_790_000_000 + 3600)) == "fresh"
    assert device.DEVICE_TOKEN_FILE.read_text() == token


def test_a_credential_that_is_recent_but_about_to_expire_is_replaced(device):
    seed = federation.load_or_create_seed()
    federation.write_credential(federation.mint_credential(seed, UUID, "ri.example.org", 1_790_000_000, 1_790_000_000 + 3600))
    assert run(federation.refresh_once(now=1_790_000_000 + 600)) == "refreshed"
    assert federation.credential_times(device.DEVICE_TOKEN_FILE.read_text())[1] - (1_790_000_000 + 600) == federation.CREDENTIAL_SECONDS


def test_an_old_credential_is_replaced_and_paired_since_is_kept(device):
    old = _credential(device, 1_790_000_000)
    now = 1_790_000_000 + federation.REFRESH_AFTER_SECONDS + 1

    assert run(federation.refresh_once(now=now)) == "refreshed"

    new = device.DEVICE_TOKEN_FILE.read_text()
    assert new != old and federation.credential_times(new)[0] == now
    assert device.DEVICE_TOKEN_FILE.stat().st_mtime == 1_700_000_000


def test_a_credential_stamped_by_a_wrong_clock_is_replaced_once_the_clock_is_right(device):
    _credential(device, 4_000_000_000)  # minted while the clock was far ahead
    assert run(federation.refresh_once(now=1_790_000_000)) == "refreshed"
    assert federation.credential_times(device.DEVICE_TOKEN_FILE.read_text())[0] == 1_790_000_000


def test_a_token_from_before_federation_is_left_alone(device):
    device.DEVICE_TOKEN_FILE.write_text("legacy-random-token")
    calls = []

    assert run(federation.refresh_once(now=1_790_000_000, transport=_server(lambda r: calls.append(r)))) == "legacy"
    assert device.DEVICE_TOKEN_FILE.read_text() == "legacy-random-token" and calls == []


def test_the_background_task_survives_a_failing_refresh(device, monkeypatch):
    attempts = []

    async def boom(*args, **kwargs):
        attempts.append(1)
        raise RuntimeError("disk full")

    async def stop(_seconds):
        raise asyncio.CancelledError

    monkeypatch.setattr(federation, "refresh_once", boom)
    monkeypatch.setattr(federation.asyncio, "sleep", stop)
    with pytest.raises(asyncio.CancelledError):
        run(federation.maintain())
    assert attempts == [1]


# ---- a device with the wrong time -----------------------------------------------------------------------------

SERVER_NOW = 1_790_000_000


def _date(seconds):
    return email.utils.formatdate(seconds, usegmt=True)


def _up(seconds=SERVER_NOW):
    return _server(lambda request: httpx.Response(200, text="ok", headers={"date": _date(seconds)}))


def test_the_server_clock_comes_from_its_date_header():
    assert run(federation.server_clock(SERVER, _up())) == SERVER_NOW
    assert run(federation.server_clock(SERVER, _server(lambda r: httpx.Response(200)))) is None  # says nothing
    assert run(federation.server_clock(SERVER, _server(lambda r: httpx.Response(200, headers={"date": "garbage"})))) is None

    def refuse(request):
        raise httpx.ConnectError("no network yet", request=request)

    assert run(federation.server_clock(SERVER, _server(refuse))) is None


def test_with_no_way_to_learn_the_servers_time_a_working_credential_is_left_alone(device, monkeypatch):
    token = _credential(device, SERVER_NOW - 100 * 3600)  # old enough that it would normally be replaced
    monkeypatch.setattr(federation.time, "time", lambda: 0)  # booted with no idea what year it is

    def refuse(request):
        raise httpx.ConnectError("no network yet", request=request)

    assert run(federation.refresh_once(transport=_server(refuse))) == "waiting"
    assert device.DEVICE_TOKEN_FILE.read_text() == token


def test_a_device_whose_clock_is_a_month_behind_gets_credentials_that_work_by_the_servers_clock(device, monkeypatch):
    behind = SERVER_NOW - 30 * 86400
    monkeypatch.setattr(federation.time, "time", lambda: behind)
    _credential(device, SERVER_NOW - federation.CREDENTIAL_SECONDS - 3600)  # expired by the server's clock

    assert run(federation.refresh_once(transport=_up())) == "refreshed"

    token = device.DEVICE_TOKEN_FILE.read_text()
    iat, exp = federation.credential_times(token)
    assert iat == SERVER_NOW and exp > SERVER_NOW + 13 * 86400  # server time, not the device's

    # an hour later the device's clock is still wrong: nothing to do
    monkeypatch.setattr(federation.time, "time", lambda: behind + 3600)
    assert run(federation.refresh_once(transport=_up(SERVER_NOW + 3600))) == "fresh"
    # NTP finally corrects the clock (a jump of a month): still nothing to do, the credential never depended on it
    monkeypatch.setattr(federation.time, "time", lambda: SERVER_NOW + 7200)
    assert run(federation.refresh_once(transport=_up(SERVER_NOW + 7200))) == "fresh"
    assert device.DEVICE_TOKEN_FILE.read_text() == token


def test_pairing_with_a_wrong_device_clock_stamps_the_credential_with_the_servers_time(device, monkeypatch):
    monkeypatch.setattr(federation.time, "time", lambda: SERVER_NOW - 400 * 86400)

    def handler(request):
        response = _pair_ok(request)
        response.headers["date"] = _date(SERVER_NOW)
        return response

    run(federation.federated_pair("ABCDE-FGHJK", "Dock", transport=_server(handler)))

    assert federation.credential_times(device.DEVICE_TOKEN_FILE.read_text())[0] == SERVER_NOW
