import asyncio
import json
import os
import stat
import subprocess
from pathlib import Path

import pytest
from fastapi import HTTPException

from . import local_server
from .local_server import LocalServer
from .local_server_api import build_router

run = asyncio.run
# The test runner copies only backend/ into a temp tree; the product root says where image/ is.
_product_root = Path(os.environ.get("PRODUCT_ROOT") or Path(__file__).parents[1])
SCRIPT = _product_root / "image/system/scripts/slide-announcer-local-server"


def _endpoint(router, path, method="GET"):
    return next(r.endpoint for r in router.routes if r.path == path and method in r.methods)


@pytest.fixture
def paths(tmp_path, monkeypatch):
    root, data, run_dir = tmp_path / "opt", tmp_path / "data" / "relief-inventory", tmp_path / "run"
    for d in (root, data, run_dir):
        d.mkdir(parents=True)
    # no real systemctl / ip / network in unit tests
    monkeypatch.setattr(local_server, "_run", _fake_run({}))
    return root, data, run_dir, tmp_path / "data"


def _fake_run(units, calls=None, ip=None):
    async def fake(*argv, timeout=10.0):
        if calls is not None:
            calls.append(argv)
        if argv[:2] == ("systemctl", "is-active"):
            return 0, units.get(argv[2], "inactive") + "\n"
        if argv[:2] == ("systemctl", "start"):
            return 0, ""
        if argv[0] == "ip":
            return 0, json.dumps(ip if ip is not None else [])
        return None, ""
    return fake


def _install(root):
    (root / "images.tar").write_bytes(b"x")
    (root / "compose.yaml").write_text("services: {}")


def _server(paths, **kw):
    root, data, run_dir, mount = paths
    return LocalServer(root, data, run_dir, data_mount=mount, **kw)


def test_not_available_when_the_image_has_no_bundle(paths):
    status = run(_server(paths).status())
    assert status["available"] is False and status["phase"] == "stopped"


def test_stale_status_file_is_not_believed_when_the_supervisor_is_gone(paths, monkeypatch):
    _install(paths[0])
    (paths[2] / "status.json").write_text(json.dumps({"phase": "running", "message": "The server is running."}))
    monkeypatch.setattr(local_server, "_run", _fake_run({}))   # unit inactive: e.g. the status survived a crash

    assert run(_server(paths).status())["phase"] == "stopped"


def test_error_phase_survives_the_unit_having_exited(paths, monkeypatch):
    _install(paths[0])
    (paths[2] / "status.json").write_text(json.dumps({"phase": "error", "message": "Not enough free space."}))

    status = run(_server(paths).status())

    assert (status["phase"], status["message"]) == ("error", "Not enough free space.")


def test_running_server_reports_urls_and_hides_docker_bridges(paths, monkeypatch):
    _install(paths[0])
    (paths[2] / "status.json").write_text(json.dumps({"phase": "running", "setup_code": "abcd2345"}))
    links = [{"ifname": "lo", "addr_info": [{"family": "inet", "local": "127.0.0.1"}]},
             {"ifname": "eth0", "addr_info": [{"family": "inet", "local": "192.168.1.20"}]},
             {"ifname": "docker0", "addr_info": [{"family": "inet", "local": "172.17.0.1"}]},
             {"ifname": "br-1a2b", "addr_info": [{"family": "inet", "local": "172.18.0.1"}]}]
    monkeypatch.setattr(local_server, "_run", _fake_run({local_server.UNIT: "active"}, ip=links))
    server = _server(paths)

    async def pending():
        return True
    monkeypatch.setattr(server, "_setup_pending", pending)

    status = run(server.status())

    assert status["running"] is True
    assert status["addresses"] == ["http://192.168.1.20:8080/"]
    assert status["local_url"] == "http://localhost:8080/"
    assert status["setup_code"] == "abcd2345"   # the wizard is still open, so the code is useful


def test_setup_code_is_not_shown_once_setup_is_done(paths, monkeypatch):
    _install(paths[0])
    (paths[2] / "status.json").write_text(json.dumps({"phase": "running", "setup_code": "abcd2345"}))
    monkeypatch.setattr(local_server, "_run", _fake_run({local_server.UNIT: "active"}))
    server = _server(paths)

    async def pending():
        return False
    monkeypatch.setattr(server, "_setup_pending", pending)

    assert run(server.status())["setup_code"] is None


def test_reset_in_progress_wins_over_everything(paths, monkeypatch):
    _install(paths[0])
    monkeypatch.setattr(local_server, "_run", _fake_run({local_server.ACTION_UNITS["reset"]: "activating"}))

    status = run(_server(paths).status())

    assert status["phase"] == "resetting" and status["busy"] is True


def test_actions_start_the_matching_unit_without_blocking(paths, monkeypatch):
    _install(paths[0])
    calls = []
    monkeypatch.setattr(local_server, "_run", _fake_run({}, calls))

    run(_server(paths).act("enable"))

    assert ("systemctl", "start", "--no-block", "slide-announcer-local-server-enable.service") in calls


def test_actions_refused_when_the_image_lacks_the_bundle(paths):
    with pytest.raises(local_server.LocalServerError) as caught:
        run(_server(paths).act("enable"))
    assert caught.value.status == 409


def test_reset_needs_explicit_confirmation(paths, monkeypatch):
    _install(paths[0])
    calls = []
    monkeypatch.setattr(local_server, "_run", _fake_run({}, calls))
    reset = _endpoint(build_router(_server(paths)), "/api/local/local-server/reset", "POST")

    with pytest.raises(HTTPException) as caught:
        run(reset(build_router.__globals__["Reset"](confirm=False)))
    assert caught.value.status_code == 400
    assert not any("reset.service" in a for call in calls for a in call)

    run(reset(build_router.__globals__["Reset"](confirm=True)))
    assert ("systemctl", "start", "--no-block", "slide-announcer-local-server-reset.service") in calls


def test_low_space_only_matters_before_the_images_are_unpacked(paths, monkeypatch):
    _install(paths[0])
    monkeypatch.setattr(local_server.shutil, "disk_usage", lambda p: type("U", (), {"free": 1_000_000_000})())
    assert run(_server(paths).status())["low_space"] is True

    (paths[1] / "state").mkdir()
    (paths[1] / "state" / "loaded-image-id").write_text("sha256:abc")
    assert run(_server(paths).status())["low_space"] is False


# --- "use as home": the device's own site -------------------------------------------------------

class ServerUrlError(Exception):
    pass


class FakePairing:
    ServerUrlError = ServerUrlError

    def normalize_server_url(self, raw):
        raw = raw.strip()
        if not raw:
            raise ServerUrlError("Enter the server's web address")
        return (raw if "://" in raw else f"https://{raw}").rstrip("/")

    def __init__(self, tmp, confirmed=None, paired=False):
        self.SERVER_URL_FILE = tmp / "server-url"
        if confirmed:
            self.SERVER_URL_FILE.write_text(confirmed + "\n")
        self.paired = paired

    def read_confirmed_server_url(self):
        try:
            return self.SERVER_URL_FILE.read_text().strip() or None
        except OSError:
            return None

    def write_server_url(self, url):
        self.SERVER_URL_FILE.write_text(url + "\n")

    def is_paired(self):
        return self.paired

    def hostname_change_pending(self, host):
        return host != "rstation-111111"


def _home_server(paths, tmp_path, probe_state="ok", **kw):
    root, data, run_dir, mount = paths

    async def probe(url):
        return {"state": probe_state, "host": url.split("//")[-1], "detail": "timed out"}
    return LocalServer(root, data, run_dir, data_mount=mount, previous_home=tmp_path / "before",
                       pairing=FakePairing(tmp_path, **kw), probe=probe)


def test_home_remembers_the_old_server_and_restores_it(paths, tmp_path):
    server = _home_server(paths, tmp_path, confirmed="https://relief.example.org")

    server.set_home(True)
    assert server.pairing.read_confirmed_server_url() == "http://localhost:8080"
    assert server.home_state()["is_home"] is True

    server.set_home(True)   # idempotent: must not overwrite the remembered address with localhost
    server.set_home(False)
    assert server.pairing.read_confirmed_server_url() == "https://relief.example.org"
    assert server.home_state()["is_home"] is False


def test_home_with_no_previous_server_goes_back_to_none(paths, tmp_path):
    server = _home_server(paths, tmp_path)

    server.set_home(True)
    server.set_home(False)

    assert server.pairing.read_confirmed_server_url() is None


def test_home_is_refused_while_paired_to_a_site(paths, tmp_path):
    server = _home_server(paths, tmp_path, confirmed="https://relief.example.org", paired=True)

    with pytest.raises(local_server.LocalServerError) as caught:
        server.set_home(True)

    assert caught.value.status == 409
    assert server.pairing.read_confirmed_server_url() == "https://relief.example.org"


def test_external_site_can_be_set_after_setup_while_unpaired(paths, tmp_path):
    server = _home_server(paths, tmp_path, confirmed="http://localhost:8080")
    server.set_home(False)

    run(server.set_site("relief.example.org"))

    assert server.pairing.read_confirmed_server_url() == "https://relief.example.org"
    assert server.home_state()["is_home"] is False
    assert not (tmp_path / "before").exists()   # "go back" must not resurrect an older choice


def test_external_site_must_answer_unless_staff_insist(paths, tmp_path):
    server = _home_server(paths, tmp_path, probe_state="unreachable", confirmed="http://localhost:8080")

    with pytest.raises(local_server.LocalServerError) as caught:
        run(server.set_site("https://down.example.org"))
    assert caught.value.status == 422 and "timed out" in str(caught.value)
    assert server.pairing.read_confirmed_server_url() == "http://localhost:8080"

    run(server.set_site("https://down.example.org", verify=False))
    assert server.pairing.read_confirmed_server_url() == "https://down.example.org"


def test_bad_address_is_a_422(paths, tmp_path):
    with pytest.raises(local_server.LocalServerError) as caught:
        run(_home_server(paths, tmp_path).set_site("  "))
    assert caught.value.status == 422


def test_site_is_locked_once_paired_until_unpair_or_factory_reset(paths, tmp_path):
    server = _home_server(paths, tmp_path, confirmed="https://relief.example.org", paired=True)

    with pytest.raises(local_server.LocalServerError) as caught:
        run(server.set_site("https://other.example.org"))

    assert caught.value.status == 409 and "Unpair" in str(caught.value)
    assert server.pairing.read_confirmed_server_url() == "https://relief.example.org"
    server.pairing.paired = False   # after an unpair (which keeps the address) it opens up again
    run(server.set_site("https://other.example.org"))
    assert server.pairing.read_confirmed_server_url() == "https://other.example.org"


def test_status_carries_home_and_hostname_facts(paths, tmp_path):
    server = _home_server(paths, tmp_path, confirmed="http://localhost:8080")

    status = run(server.status())

    assert status["is_home"] is True and status["paired"] is False
    assert status["hostname"] and status["hostname_change_pending"] in (True, False)


# --- the root supervisor script, against a fake docker/systemctl ------------------------------

FAKE_DOCKER = """#!/bin/sh
echo "docker $*" >> "$FAKE_LOG"
case "$1 $2" in
  "image inspect") [ -e "$FAKE_STATE/loaded" ] ;;
  "load -i") touch "$FAKE_STATE/loaded" ;;
  "compose version") exit 0 ;;
  *) exit 0 ;;
esac
"""
FAKE_SYSTEMCTL = """#!/bin/sh
echo "systemctl $*" >> "$FAKE_LOG"
exit 0
"""
FAKE_CURL = "#!/bin/sh\nexit 22\n"   # the app never comes up: enough for the phases under test


@pytest.fixture
def sandbox(tmp_path):
    root, data, run_dir, bindir, state = (tmp_path / n for n in ("opt", "data", "run", "bin", "state"))
    for d in (root, bindir, state):
        d.mkdir()
    for name, body in (("docker", FAKE_DOCKER), ("systemctl", FAKE_SYSTEMCTL), ("curl", FAKE_CURL)):
        (bindir / name).write_text(body)
        (bindir / name).chmod(0o755)
    (root / "images.tar").write_bytes(b"image")
    (root / "compose.yaml").write_text("services: {}\n")
    (root / "images.env").write_text("RI_APP_IMAGE=app:1\nRI_DB_IMAGE=db:1\n")
    (root / "manifest.json").write_text('{"app_image": "app:1", "app_image_id": "sha256:aaa"}')
    env = {**os.environ, "RI_LOCAL_ROOT": str(root), "RI_LOCAL_DATA": str(data), "RI_LOCAL_RUN": str(run_dir),
           "RI_LOCAL_DOCKER_ROOT": str(tmp_path / "docker"), "RI_LOCAL_MIN_FREE_BYTES": "1",
           "DOCKER": str(bindir / "docker"), "SYSTEMCTL": str(bindir / "systemctl"), "CURL": str(bindir / "curl"),
           "FAKE_LOG": str(tmp_path / "log"), "FAKE_STATE": str(state), "PATH": f"{bindir}:{os.environ['PATH']}"}
    (tmp_path / "log").write_text("")
    return type("Box", (), {"env": env, "data": data, "run_dir": run_dir, "log": tmp_path / "log", "root": root})


def _script(box, *args, timeout=20):
    return subprocess.run([str(SCRIPT), *args], env=box.env, capture_output=True, text=True, timeout=timeout)


def test_enable_sets_the_flag_creates_folders_and_starts_the_supervisor(sandbox):
    result = _script(sandbox, "enable")

    assert result.returncode == 0, result.stderr
    assert (sandbox.data / "enabled").exists()
    assert all((sandbox.data / d).is_dir() for d in ("db", "storage", "secrets", "backups", "state"))
    assert "systemctl start --no-block slide-announcer-local-server.service" in sandbox.log.read_text()


def test_disable_clears_the_flag_and_stops_the_supervisor(sandbox):
    _script(sandbox, "enable")
    result = _script(sandbox, "disable")

    assert result.returncode == 0
    assert not (sandbox.data / "enabled").exists()
    assert "systemctl stop --no-block slide-announcer-local-server.service" in sandbox.log.read_text()


def test_run_loads_images_once_brings_the_stack_up_and_stops_gracefully(sandbox):
    _script(sandbox, "enable")
    proc = subprocess.Popen([str(SCRIPT), "run"], env=sandbox.env, stderr=subprocess.PIPE, text=True)
    try:
        status_file = sandbox.run_dir / "status.json"
        for _ in range(100):
            if status_file.exists() and "compose" in sandbox.log.read_text() and "up -d" in sandbox.log.read_text():
                break
            subprocess.run(["sleep", "0.1"])
        log = sandbox.log.read_text()
        assert "docker load" in log
        assert "up -d --no-build --pull never" in log
        status = json.loads(status_file.read_text())
        assert status["phase"] in ("starting", "degraded")
        assert len(status["setup_code"]) == 8                    # generated, for the Settings page to show
        assert (sandbox.data / "state" / "setup-code").stat().st_mode & 0o077 == 0   # private to root
        proc.terminate()
        assert proc.wait(timeout=20) == 0
    finally:
        if proc.poll() is None:
            proc.kill()
    log = sandbox.log.read_text()
    assert "compose -p reliefinventory" in log and " stop -t 60" in log
    assert "systemctl stop docker.service docker.socket" in log
    assert json.loads((sandbox.run_dir / "status.json").read_text())["phase"] == "stopped"


def test_images_are_not_reloaded_on_the_next_start(sandbox):
    (sandbox.data / "state").mkdir(parents=True)
    (sandbox.data / "state" / "loaded-image-id").write_text("sha256:aaa")
    (Path(sandbox.env["FAKE_STATE"]) / "loaded").touch()
    (sandbox.data / "enabled").touch()
    proc = subprocess.Popen([str(SCRIPT), "run"], env=sandbox.env, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            if "up -d" in sandbox.log.read_text():
                break
            subprocess.run(["sleep", "0.1"])
        proc.terminate()
        proc.wait(timeout=20)
    finally:
        if proc.poll() is None:
            proc.kill()
    assert "docker load" not in sandbox.log.read_text()


def test_not_enough_space_is_reported_and_is_not_retried(sandbox):
    sandbox.env["RI_LOCAL_MIN_FREE_BYTES"] = str(10**15)
    (sandbox.data).mkdir(parents=True)
    result = _script(sandbox, "run")

    assert result.returncode == 3
    status = json.loads((sandbox.run_dir / "status.json").read_text())
    assert status["phase"] == "error" and "free space" in status["message"]
    assert "docker load" not in sandbox.log.read_text()


def test_image_without_the_bundle_exits_without_retrying(sandbox):
    (sandbox.root / "images.tar").unlink()

    assert _script(sandbox, "run").returncode == 3


def test_reset_erases_data_but_not_the_loaded_images(sandbox):
    _script(sandbox, "enable")
    (sandbox.data / "db" / "ibdata1").write_text("rows")
    (sandbox.data / "backups" / "x.sql.gz").write_text("dump")
    (sandbox.data / "state" / "setup-code").write_text("oldcode12")

    result = _script(sandbox, "reset")

    assert result.returncode == 0, result.stderr
    assert not (sandbox.data / "enabled").exists()
    assert list((sandbox.data / "db").iterdir()) == [] and list((sandbox.data / "backups").iterdir()) == []
    assert not (sandbox.data / "state" / "setup-code").exists()
    log = sandbox.log.read_text()
    assert "down --remove-orphans" in log           # containers go before their bind-mounted folders do
    assert "image rm" not in log and "prune" not in log
    assert json.loads((sandbox.run_dir / "status.json").read_text())["phase"] == "stopped"


def test_script_and_units_are_executable_and_named_for_polkit():
    assert SCRIPT.stat().st_mode & stat.S_IXUSR
    import re
    for unit in local_server.ACTION_UNITS.values():
        assert re.fullmatch(r"slide-announcer-[a-z0-9-]+\.service", unit)   # system/polkit/50-slide-announcer-system.rules
        assert (_product_root / "image/system" / unit).is_file()
    assert (_product_root / "image/system" / local_server.UNIT).is_file()
