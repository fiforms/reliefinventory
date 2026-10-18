"""Tests for the root helper (loaded from its script — it is deliberately not importable
from the app package) and for the backend client that talks to it."""
import asyncio
import importlib.machinery
import importlib.util
import json
import os
import shutil
import subprocess
from pathlib import Path

import pytest
from fastapi import HTTPException

from .admin_password import AdminPassword, PasswordError
from .printers_api import PasswordChange, build_router

run = asyncio.run

# The test runner copies only backend/ into a temp tree; the product root says where image/ is.
_product_root = Path(os.environ.get("PRODUCT_ROOT") or Path(__file__).parents[1])
_script = _product_root / "image/system/scripts/slide-announcer-admin-password"
_loader = importlib.machinery.SourceFileLoader("admin_password_helper", str(_script))
helper = importlib.util.module_from_spec(importlib.util.spec_from_loader(_loader.name, _loader))
_loader.exec_module(helper)

GOOD, NEWER = "correct horse", "battery staple"


class Rig:
    """The helper with a temp state dir, a fake account, and a clock/sleep it controls."""

    def __init__(self, tmp_path):
        self.password = "build-time-secret"
        self.set_calls = []
        self.slept = []
        self.now = 1_000_000.0
        self.helper = helper.Helper(
            state_dir=str(tmp_path / "state"),
            verify=lambda user, pw: user == "slideadmin" and pw == self.password,
            chpasswd=self._chpasswd, sleep=self.slept.append, now=lambda: self.now)

    def _chpasswd(self, user, pw):
        self.set_calls.append((user, pw))
        self.password = pw

    def code(self, request):
        try:
            self.helper.handle(request)
            return "ok"
        except helper.Failure as failure:
            return failure.code


@pytest.fixture
def rig(tmp_path):
    return Rig(tmp_path)


def test_first_password_needs_no_old_one_and_marks_the_device(rig):
    assert not rig.helper.is_set()
    assert rig.code({"new": GOOD}) == "ok"
    assert rig.helper.is_set() and rig.password == GOOD
    assert rig.set_calls == [("slideadmin", GOOD)]


def test_after_that_changing_requires_the_current_password(rig):
    rig.code({"new": GOOD})
    assert rig.code({"new": NEWER}) == "wrong_password"
    assert rig.code({"new": NEWER, "old": None}) == "wrong_password"
    assert rig.code({"new": NEWER, "old": "build-time-secret"}) == "wrong_password"
    assert rig.password == GOOD  # nothing changed
    assert rig.code({"new": NEWER, "old": GOOD}) == "ok"
    assert rig.password == NEWER


def test_a_wrong_guess_is_slowed(rig):
    rig.code({"new": GOOD})
    rig.code({"new": NEWER, "old": "nope"})
    assert rig.slept == [helper.DELAY_SECONDS]


def test_repeated_wrong_guesses_lock_out_even_the_right_password_until_it_expires(rig):
    rig.code({"new": GOOD})
    codes = [rig.code({"new": NEWER, "old": "nope"}) for _ in range(helper.MAX_FAILURES)]
    assert codes == ["wrong_password"] * (helper.MAX_FAILURES - 1) + ["locked"]

    assert rig.code({"new": NEWER, "old": GOOD}) == "locked"  # right password, still refused
    assert rig.password == GOOD
    try:
        rig.helper.handle({"new": NEWER, "old": GOOD})
    except helper.Failure as failure:
        assert 0 < failure.retry_after <= helper.LOCK_SECONDS + 1

    rig.now += helper.LOCK_SECONDS + 1
    assert rig.code({"new": NEWER, "old": GOOD}) == "ok"


def test_lockout_survives_a_restart_of_the_helper(rig):
    rig.code({"new": GOOD})
    for _ in range(helper.MAX_FAILURES):
        rig.code({"new": NEWER, "old": "nope"})
    fresh = helper.Helper(state_dir=rig.helper.state_dir, verify=lambda u, p: p == GOOD, chpasswd=rig._chpasswd,
                          sleep=lambda s: None, now=lambda: rig.now)
    with pytest.raises(helper.Failure) as locked:
        fresh.handle({"new": NEWER, "old": GOOD})
    assert locked.value.code == "locked"


def test_a_successful_change_clears_earlier_failures(rig):
    rig.code({"new": GOOD})
    for _ in range(helper.MAX_FAILURES - 1):
        rig.code({"new": NEWER, "old": "nope"})
    assert rig.code({"new": NEWER, "old": GOOD}) == "ok"
    assert [rig.code({"new": GOOD, "old": "nope"}) for _ in range(2)] == ["wrong_password"] * 2


def test_a_clock_jump_cannot_lock_the_device_for_longer_than_the_lock_time(rig):
    rig.code({"new": GOOD})
    rig.helper._store_failures(5, rig.now + 10 * helper.LOCK_SECONDS)  # wildly in the future
    assert rig.code({"new": NEWER, "old": GOOD}) == "ok"


@pytest.mark.parametrize("new, expected", [
    ("short", "invalid_length"), ("x" * (helper.MAX_LENGTH + 1), "invalid_length"), (None, "invalid_length"),
    (12345678, "invalid_length"), ("line\nbreak-here", "invalid_chars"), ("nul\x00byte-here", "invalid_chars"),
])
def test_bad_new_passwords_are_refused_before_anything_changes(rig, new, expected):
    assert rig.code({"new": new}) == expected
    assert rig.set_calls == [] and not rig.helper.is_set()


def test_a_password_with_colons_and_spaces_is_fine(rig):
    assert rig.code({"new": "a:b c:d é!"}) == "ok"


def test_a_failing_chpasswd_does_not_mark_the_device_set(rig):
    def boom(user, pw):
        raise helper.Failure("error")
    rig.helper._chpasswd = boom
    assert rig.code({"new": GOOD}) == "error"
    assert not rig.helper.is_set()  # still first-run: not locked out of ever setting it


def test_a_malformed_request_is_an_error(rig):
    assert rig.code(["not", "a", "dict"]) == "error"


# ---- the exchange directory: root must not be steered by what the backend's user plants

@pytest.fixture
def exchange(tmp_path):
    d = tmp_path / "x"
    d.mkdir()
    fd = os.open(d, os.O_RDONLY | os.O_DIRECTORY)
    yield d, fd
    os.close(fd)


def test_request_is_read_then_deleted(exchange):
    d, fd = exchange
    (d / "request").write_text(json.dumps({"new": GOOD, "old": None}))
    assert helper.read_request(fd, os.getuid()) == {"new": GOOD, "old": None}
    assert not (d / "request").exists()


def test_a_symlinked_request_is_not_followed(exchange, tmp_path):
    d, fd = exchange
    secret = tmp_path / "secret"
    secret.write_text('{"new": "from-elsewhere"}')
    (d / "request").symlink_to(secret)
    with pytest.raises(helper.Failure):
        helper.read_request(fd, os.getuid())
    assert secret.exists()


def test_a_request_owned_by_someone_else_is_refused(exchange):
    d, fd = exchange
    (d / "request").write_text("{}")
    with pytest.raises(helper.Failure):
        helper.read_request(fd, os.getuid() + 1)


def test_an_oversized_or_non_json_request_is_refused(exchange):
    d, fd = exchange
    (d / "request").write_text("x" * (helper.MAX_REQUEST_BYTES + 1))
    with pytest.raises(helper.Failure):
        helper.read_request(fd, os.getuid())
    (d / "request").write_text("not json")
    with pytest.raises(helper.Failure):
        helper.read_request(fd, os.getuid())


def test_a_fifo_request_does_not_hang_the_helper(exchange):
    d, fd = exchange
    os.mkfifo(d / "request")
    with pytest.raises(helper.Failure):
        helper.read_request(fd, os.getuid())


def test_result_replaces_a_planted_symlink_instead_of_writing_through_it(exchange, tmp_path):
    d, fd = exchange
    target = tmp_path / "victim"
    target.write_text("keep me")
    (d / "result").symlink_to(target)
    helper.write_result(fd, "locked", 60)
    assert target.read_text() == "keep me"
    assert json.loads((d / "result").read_text()) == {"code": "locked", "retry_after": 60}


# ---- checking a password against a real shadow file

def _hash(method, password):
    """A shadow-style hash made by an independent tool (the stdlib `crypt` module is gone in 3.13)."""
    if method == "yescrypt":
        if not shutil.which("mkpasswd"):
            pytest.skip("mkpasswd not installed")
        return subprocess.run(["mkpasswd", "-m", "yescrypt", "-s"], input=password, capture_output=True, text=True, check=True).stdout.strip()
    if not shutil.which("openssl"):
        pytest.skip("openssl not installed")
    return subprocess.run(["openssl", "passwd", f"-{method}", "-stdin"], input=password, capture_output=True, text=True, check=True).stdout.strip()


@pytest.mark.parametrize("method", ["6", "5", "yescrypt"])
def test_verify_password_against_a_shadow_hash(tmp_path, method):
    shadow = tmp_path / "shadow"
    shadow.write_text(f"root:*:1:0:::::\nslideadmin:{_hash(method, GOOD)}:1:0:99999:7:::\n")
    assert helper.verify_password("slideadmin", GOOD, str(shadow))
    assert not helper.verify_password("slideadmin", GOOD + "x", str(shadow))
    assert not helper.verify_password("nobody", GOOD, str(shadow))


@pytest.mark.parametrize("stored", ["!", "*", "!$6$abc$def", ""])
def test_a_locked_or_empty_account_never_verifies(tmp_path, stored):
    shadow = tmp_path / "shadow"
    shadow.write_text(f"slideadmin:{stored}:1:0:99999:7:::\n")
    assert not helper.verify_password("slideadmin", "", str(shadow))
    assert not helper.verify_password("slideadmin", GOOD, str(shadow))


# ---- main(): the whole request -> result flow, as root would run it

def test_main_reads_the_request_and_answers_in_the_result_file(tmp_path, monkeypatch):
    exchange_dir, state = tmp_path / "x", tmp_path / "state"
    exchange_dir.mkdir()
    changed = []
    monkeypatch.setattr(helper.os, "geteuid", lambda: 0)
    monkeypatch.setattr(helper.pwd, "getpwnam", lambda name: type("P", (), {"pw_uid": os.getuid()})())
    monkeypatch.setattr(helper, "EXCHANGE_DIR", str(exchange_dir))
    monkeypatch.setattr(helper, "STATE_DIR", str(state))
    monkeypatch.setattr(helper, "run_chpasswd", lambda user, pw: changed.append((user, pw)))
    monkeypatch.setattr(helper, "verify_password", lambda user, pw: pw == GOOD)
    monkeypatch.setattr(helper, "DELAY_SECONDS", 0)

    def ask(request):
        (exchange_dir / "request").write_text(json.dumps(request))
        assert helper.main() == 0
        assert not (exchange_dir / "request").exists()
        return json.loads((exchange_dir / "result").read_text())["code"]

    assert ask({"new": GOOD}) == "ok"  # first run
    assert ask({"new": NEWER, "old": "wrong-one"}) == "wrong_password"
    assert ask({"new": NEWER, "old": GOOD}) == "ok"
    assert changed == [("slideadmin", GOOD), ("slideadmin", NEWER)]


def test_main_refuses_to_run_unprivileged(monkeypatch):
    monkeypatch.setattr(helper.os, "geteuid", lambda: 1000)
    assert helper.main() == 2


# ---- main(): the whole request -> result flow, as root would run it

def test_main_reads_the_request_and_answers_in_the_result_file(tmp_path, monkeypatch):
    exchange_dir, state = tmp_path / "x", tmp_path / "state"
    exchange_dir.mkdir()
    changed = []
    monkeypatch.setattr(helper.os, "geteuid", lambda: 0)
    monkeypatch.setattr(helper.pwd, "getpwnam", lambda name: type("P", (), {"pw_uid": os.getuid()})())
    monkeypatch.setattr(helper, "EXCHANGE_DIR", str(exchange_dir))
    monkeypatch.setattr(helper, "STATE_DIR", str(state))
    monkeypatch.setattr(helper, "run_chpasswd", lambda user, pw: changed.append((user, pw)))
    monkeypatch.setattr(helper, "verify_password", lambda user, pw: pw == GOOD)
    monkeypatch.setattr(helper, "DELAY_SECONDS", 0)

    def ask(request):
        (exchange_dir / "request").write_text(json.dumps(request))
        assert helper.main() == 0
        assert not (exchange_dir / "request").exists()
        return json.loads((exchange_dir / "result").read_text())["code"]

    assert ask({"new": GOOD}) == "ok"  # first run
    assert ask({"new": NEWER, "old": "wrong-one"}) == "wrong_password"
    assert ask({"new": NEWER, "old": GOOD}) == "ok"
    assert changed == [("slideadmin", GOOD), ("slideadmin", NEWER)]


def test_main_refuses_to_run_unprivileged(monkeypatch):
    monkeypatch.setattr(helper.os, "geteuid", lambda: 1000)
    assert helper.main() == 2


# ---- the backend client

class FakeHelperUnit:
    """Stands in for `systemctl start slide-announcer-admin-password.service`."""

    def __init__(self, directory, result=None, returncode=0):
        self.dir, self.result, self.returncode = directory, result, returncode
        self.seen_request = None

    async def __call__(self):
        self.seen_request = json.loads((self.dir / "request").read_text())
        mode = (self.dir / "request").stat().st_mode & 0o777
        assert mode == 0o600  # never readable by other users
        if self.result is not None:
            (self.dir / "result").write_text(json.dumps(self.result))
        return self.returncode, ""


def client(tmp_path, **kwargs):
    d = tmp_path / "run"
    unit = FakeHelperUnit(d, **kwargs)
    return AdminPassword(exchange_dir=d, marker=tmp_path / "marker", start_unit=unit), unit


def test_set_state_follows_the_marker(tmp_path):
    c, _ = client(tmp_path)
    assert not c.is_set()
    (tmp_path / "marker").write_text("1\n")
    assert c.is_set()


def test_a_successful_change_passes_both_passwords_and_leaves_nothing_behind(tmp_path):
    c, unit = client(tmp_path, result={"code": "ok"})
    run(c.change("old-password", "new-password"))
    assert unit.seen_request == {"old": "old-password", "new": "new-password"}
    assert list((tmp_path / "run").iterdir()) == []


@pytest.mark.parametrize("result, status", [
    ({"code": "wrong_password"}, 403), ({"code": "locked", "retry_after": 200}, 429),
    ({"code": "invalid_chars"}, 400), ({"code": "error"}, 500), ({"code": "mystery"}, 500), (None, 502),
])
def test_helper_answers_map_to_http_statuses(tmp_path, result, status):
    c, _ = client(tmp_path, result=result, returncode=0 if result else 1)
    with pytest.raises(PasswordError) as err:
        run(c.change("old-password", "new-password"))
    assert err.value.status == status
    assert "new-password" not in str(err.value) and "old-password" not in str(err.value)


def test_lockout_message_gives_minutes(tmp_path):
    c, _ = client(tmp_path, result={"code": "locked", "retry_after": 200})
    with pytest.raises(PasswordError) as err:
        run(c.change("old-password", "new-password"))
    assert "4 minutes" in str(err.value)


def test_a_stale_result_from_an_earlier_request_is_never_believed(tmp_path):
    c, _ = client(tmp_path, result=None, returncode=1)
    (tmp_path / "run").mkdir()
    (tmp_path / "run" / "result").write_text('{"code": "ok"}')
    with pytest.raises(PasswordError) as err:
        run(c.change("old-password", "new-password"))
    assert err.value.status == 502


def test_a_too_short_password_never_reaches_the_helper(tmp_path):
    c, unit = client(tmp_path, result={"code": "ok"})
    with pytest.raises(PasswordError) as err:
        run(c.change(None, "short"))
    assert err.value.status == 400 and unit.seen_request is None


def test_endpoints(tmp_path):
    c, _ = client(tmp_path, result={"code": "ok"})
    router = build_router(cups=None, admin_password=c)
    get = next(r.endpoint for r in router.routes if r.path == "/api/local/printers/admin-password" and "GET" in r.methods)
    post = next(r.endpoint for r in router.routes if r.path == "/api/local/printers/admin-password" and "POST" in r.methods)
    assert get() == {"set": False}
    assert run(post(PasswordChange(new="new-password"))) == {"set": True}

    bad, _ = client(tmp_path, result={"code": "wrong_password"})
    post = next(r.endpoint for r in build_router(cups=None, admin_password=bad).routes
                if r.path == "/api/local/printers/admin-password" and "POST" in r.methods)
    with pytest.raises(HTTPException) as err:
        run(post(PasswordChange(old="x", new="new-password")))
    assert err.value.status_code == 403
