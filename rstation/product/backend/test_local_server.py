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


def test_channel_defaults_to_latest_and_is_saved_for_the_update_script(paths):
    server = _server(paths)
    assert run(server.status())["channel"] == "latest"

    server.set_channel("edge")

    assert (paths[1] / "state" / "channel").read_text().strip() == "edge"
    assert server.channel() == "edge"
    with pytest.raises(local_server.LocalServerError) as caught:
        server.set_channel("../etc")
    assert caught.value.status == 400
    assert server.channel() == "edge"


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


# --- updates: pulling :latest, and never stepping backwards ------------------------------------

def test_update_needs_the_server_running(paths, monkeypatch):
    _install(paths[0])
    calls = []
    monkeypatch.setattr(local_server, "_run", _fake_run({}, calls))   # supervisor inactive: stopped

    with pytest.raises(local_server.LocalServerError) as caught:
        run(_server(paths).act("update"))

    assert caught.value.status == 409
    assert not any("update.service" in a for call in calls for a in call)


def test_update_starts_its_unit_when_running(paths, monkeypatch):
    _install(paths[0])
    (paths[2] / "status.json").write_text(json.dumps({"phase": "running"}))
    calls = []
    monkeypatch.setattr(local_server, "_run", _fake_run({local_server.UNIT: "active"}, calls))

    status = run(_server(paths).status())
    assert status["can_update"] is True
    run(_server(paths).act("update"))

    assert ("systemctl", "start", "--no-block", "slide-announcer-local-server-update.service") in calls
    assert hasattr(build_router(_server(paths)), "routes")
    assert _endpoint(build_router(_server(paths)), "/api/local/local-server/update", "POST")


def test_nothing_else_may_run_while_an_update_is_in_progress(paths, monkeypatch):
    _install(paths[0])
    (paths[2] / "status.json").write_text(json.dumps({"phase": "running"}))
    (paths[1] / "state").mkdir()
    (paths[1] / "state" / "update.json").write_text(json.dumps({"phase": "downloading", "message": "Downloading…"}))
    monkeypatch.setattr(local_server, "_run", _fake_run({local_server.UNIT: "active", local_server.ACTION_UNITS["update"]: "activating"}))
    server = _server(paths)

    status = run(server.status())
    assert status["update_busy"] is True and status["can_update"] is False
    for action in ("update", "disable", "reset"):
        with pytest.raises(local_server.LocalServerError) as caught:
            run(server.act(action))
        assert caught.value.status == 409


def test_an_update_whose_unit_vanished_is_reported_as_interrupted(paths, monkeypatch):
    _install(paths[0])
    (paths[1] / "state").mkdir()
    (paths[1] / "state" / "update.json").write_text(json.dumps({"phase": "installing", "message": "Installing…"}))

    status = run(_server(paths).status())

    assert status["update"]["phase"] == "failed" and status["update_busy"] is False


def test_installed_version_prefers_what_the_supervisor_recorded_over_the_baked_one(paths):
    _install(paths[0])
    (paths[0] / "manifest.json").write_text(json.dumps({"app_version": "0.1.5"}))
    assert run(_server(paths).status())["app_version"] == "0.1.5"

    (paths[1] / "state").mkdir()
    (paths[1] / "state" / "app-version").write_text("0.2.0")
    assert run(_server(paths).status())["app_version"] == "0.2.0"


# The fake Docker keeps one "image store": the id under the :latest tag, and each id's version. `pull` swaps in
# FAKE_STATE/pull_id; `load` swaps in load_id. The app answers /up only while the running id isn't broken_id.
UPDATE_DOCKER = """#!/bin/sh
echo "docker $*" >> "$FAKE_LOG"
S="$FAKE_STATE"
case "$1 $2" in
  "image inspect")
    [ -e "$S/loaded" ] || exit 1
    case "$*" in
      *Config.Env*) v="$(cat "$S/ver_$(cat "$S/id")" 2>/dev/null)"; [ -n "$v" ] && echo "RI_VERSION=$v"; exit 0 ;;
      *.Id*) cat "$S/id"; echo; exit 0 ;;
    esac
    exit 0 ;;
  "pull "*) [ -e "$S/pull_fail" ] && { echo "denied: no" >&2; exit 1; }
    case "$3" in *:edge) cp "$S/pull_id" "$S/edge_id" ;; *) cp "$S/pull_id" "$S/id" ;; esac; exit 0 ;;
  "tag "*) case "$2" in *:edge) cp "$S/edge_id" "$S/id" ;; *) echo "$2" > "$S/id" ;; esac; exit 0 ;;
  "load -i") touch "$S/loaded"; cp "$S/load_id" "$S/id"; exit 0 ;;
  "compose -p")
    case "$*" in
      *instance:backup*) [ -e "$S/backup_fail" ] && { echo "The provisioner is busy." >&2; exit 1; }; echo "instance backup written: /backups/instance/x.zip"; exit 0 ;;
    esac ;;
esac
exit 0
"""
UPDATE_SYSTEMCTL = """#!/bin/sh
echo "systemctl $*" >> "$FAKE_LOG"
case "$1 $2" in
  "is-active --quiet") [ -e "$FAKE_STATE/active" ] ;;
  "start slide-announcer-local-server-update.service") "$FAKE_SCRIPT" update ;;   # a oneshot unit: blocks until it is done
esac
"""
UPDATE_CURL = """#!/bin/sh
case "$*" in
  *127.0.0.1*) [ "$(cat "$FAKE_STATE/id")" != "$(cat "$FAKE_STATE/broken_id" 2>/dev/null)" ] ;;
  *) [ ! -e "$FAKE_STATE/offline" ] ;;
esac
"""


@pytest.fixture
def upd(sandbox):
    state = Path(sandbox.env["FAKE_STATE"])
    for name, body in (("docker", UPDATE_DOCKER), ("systemctl", UPDATE_SYSTEMCTL), ("curl", UPDATE_CURL)):
        f = Path(sandbox.env[name.upper()])
        f.write_text(body)
        f.chmod(0o755)
    sandbox.env["RI_LOCAL_UPDATE_WAIT_SECONDS"] = "1"
    sandbox.env["FAKE_SCRIPT"] = str(SCRIPT)
    (state / "loaded").touch()
    (state / "active").touch()
    (state / "id").write_text("sha256:old\n")
    (state / "ver_sha256:old").write_text("1.0.0")
    (state / "pull_id").write_text("sha256:new")
    (state / "ver_sha256:new").write_text("1.1.0")
    (sandbox.root / "images.env").write_text("RI_APP_IMAGE=ghcr.io/x/app:latest\nRI_DB_IMAGE=db:1\n")
    (sandbox.data / "state").mkdir(parents=True)
    sandbox.state = state
    sandbox.result = lambda: json.loads((sandbox.data / "state" / "update.json").read_text())
    return sandbox


def test_update_installs_a_newer_version_and_records_it(upd):
    result = _script(upd, "update")

    assert result.returncode == 0, result.stderr
    outcome = upd.result()
    assert (outcome["phase"], outcome["from"], outcome["to"]) == ("updated", "1.0.0", "1.1.0")
    assert (upd.data / "state" / "app-version").read_text() == "1.1.0"
    log = upd.log.read_text()
    assert "pull ghcr.io/x/app:latest" in log and "up -d --no-build --pull never" in log


def test_the_edge_channel_pulls_the_edge_tag_and_installs_an_unversioned_build(upd):
    (upd.data / "state" / "channel").write_text("edge\n")
    (upd.state / "pull_id").write_text("sha256:edge")   # edge builds carry no RI_VERSION

    result = _script(upd, "update")

    assert result.returncode == 0, result.stderr
    assert upd.result()["phase"] == "updated"
    assert (upd.state / "id").read_text().strip() == "sha256:edge"
    log = upd.log.read_text()
    assert "pull ghcr.io/x/app:edge" in log and "tag ghcr.io/x/app:edge ghcr.io/x/app:latest" in log
    assert "up -d --no-build --pull never" in log


def test_update_with_nothing_new_changes_nothing(upd):
    (upd.state / "pull_id").write_text("sha256:old")

    assert _script(upd, "update").returncode == 0

    assert upd.result()["phase"] == "current"
    assert "up -d" not in upd.log.read_text()


def test_an_older_registry_image_never_replaces_a_newer_installed_one(upd):
    (upd.state / "pull_id").write_text("sha256:ancient")
    (upd.state / "ver_sha256:ancient").write_text("0.9.0")

    assert _script(upd, "update").returncode == 0

    assert upd.result()["phase"] == "current"
    assert (upd.state / "id").read_text().strip() == "sha256:old"   # the tag was put back
    assert "up -d" not in upd.log.read_text()


def test_an_unversioned_image_is_not_installed(upd):
    (upd.state / "pull_id").write_text("sha256:mystery")

    assert _script(upd, "update").returncode == 1

    assert upd.result()["phase"] == "failed" and "version number" in upd.result()["message"]
    assert (upd.state / "id").read_text().strip() == "sha256:old"


def test_update_says_so_when_offline_and_downloads_nothing(upd):
    (upd.state / "offline").touch()

    assert _script(upd, "update").returncode == 1

    assert "Internet" in upd.result()["message"]
    assert "docker pull" not in upd.log.read_text()


def test_update_refuses_when_the_server_is_not_started(upd):
    (upd.state / "active").unlink()

    assert _script(upd, "update").returncode == 1

    assert "Start the local server" in upd.result()["message"]
    assert "docker pull" not in upd.log.read_text()


def test_a_failed_download_leaves_the_running_version_alone(upd):
    (upd.state / "pull_fail").touch()

    assert _script(upd, "update").returncode == 1

    assert upd.result()["phase"] == "failed"
    assert (upd.state / "id").read_text().strip() == "sha256:old"


def test_a_new_version_that_never_starts_is_rolled_back(upd):
    (upd.state / "broken_id").write_text("sha256:new")

    assert _script(upd, "update", timeout=60).returncode == 1

    outcome = upd.result()
    assert outcome["phase"] == "failed" and "put back" in outcome["message"]
    assert (upd.state / "id").read_text().strip() == "sha256:old"
    assert (upd.data / "state" / "app-version").read_text() == "1.0.0"


def _supervise(box):
    """Runs the supervisor until it has issued `compose up`, then stops it."""
    (box.data / "enabled").touch()
    proc = subprocess.Popen([str(SCRIPT), "run"], env=box.env, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            if "up -d" in box.log.read_text():
                break
            subprocess.run(["sleep", "0.1"])
        proc.terminate()
        proc.wait(timeout=20)
    finally:
        if proc.poll() is None:
            proc.kill()
    return box.log.read_text()


def _baked(box, version, image_id="sha256:baked"):
    (box.root / "manifest.json").write_text(json.dumps({"app_image_id": image_id, "app_version": version}))
    (Path(box.env["FAKE_STATE"]) / "load_id").write_text(image_id)
    (Path(box.env["FAKE_STATE"]) / f"ver_{image_id}").write_text(version)


def test_a_baked_image_that_is_not_newer_does_not_overwrite_an_updated_one(upd):
    (upd.state / "ver_sha256:old").write_text("2.0.0")   # updated from the registry since the OS image was built
    _baked(upd, "1.5.0")

    log = _supervise(upd)

    assert "docker load" not in log
    assert (upd.state / "id").read_text().strip() == "sha256:old"
    assert (upd.data / "state" / "loaded-image-id").read_text() == "sha256:baked"   # decided once, not on every boot
    assert (upd.data / "state" / "app-version").read_text() == "2.0.0"


def test_a_newer_baked_image_replaces_the_installed_one(upd):
    _baked(upd, "3.0.0")

    log = _supervise(upd)

    assert "docker load" in log
    assert (upd.state / "id").read_text().strip() == "sha256:baked"
    assert (upd.data / "state" / "app-version").read_text() == "3.0.0"


def test_the_same_baked_version_is_not_reloaded(upd):
    _baked(upd, "1.0.0", image_id="sha256:rebuilt")

    assert "docker load" not in _supervise(upd)


# --- requests from the app (check / update / backup), answered by `requests` ---------------------------------------
REQUEST_CURL = """#!/bin/sh
S="$FAKE_STATE"
echo "curl $*" >> "$FAKE_LOG"
prev=""; for a; do [ "$prev" = "-d" ] && body="$a"; prev="$a"; done
case "$*" in
  *api/instance/update/status*) [ -e "$S/report_down" ] && exit 22; echo "$body" >> "$S/reports"; exit 0 ;;
  *api/instance/update*) cat "$S/poll"; exit 0 ;;
  *127.0.0.1*) [ "$(cat "$S/id")" != "$(cat "$S/broken_id" 2>/dev/null)" ] ;;
  *) exit 0 ;;
esac
"""


@pytest.fixture
def req(upd):
    f = Path(upd.env["CURL"])
    f.write_text(REQUEST_CURL)
    f.chmod(0o755)
    upd.env["RI_LOCAL_REPORT_RETRY_SECONDS"] = "0"
    upd.env["RI_LOCAL_REPORT_SLEEP"] = "0"
    upd.poll = lambda action: (upd.state / "poll").write_text(json.dumps({"action": action, "state": "requested"}))
    upd.reports = lambda: [json.loads(line) for line in (upd.state / "reports").read_text().splitlines()] if (upd.state / "reports").exists() else []
    return upd


def test_the_secret_is_made_once_and_handed_to_the_stack(req):
    req.poll(None)
    assert _script(req, "requests").returncode == 0
    secret = (req.data / "state" / "update-secret").read_text()
    assert len(secret) == 48 and oct((req.data / "state" / "update-secret").stat().st_mode & 0o777) == "0o600"
    _script(req, "requests")
    assert (req.data / "state" / "update-secret").read_text() == secret
    assert f"Authorization: Bearer {secret}" in req.log.read_text()
    assert "RI_UPDATE_SECRET" in (_product_root / "offline" / "compose.yaml").read_text()


def test_nothing_waiting_reports_nothing(req):
    req.poll(None)
    _script(req, "requests")
    assert req.reports() == []


def test_an_update_request_is_installed_and_reported(req):
    req.poll("update")

    result = _script(req, "requests")

    assert result.returncode == 0, result.stderr
    states = [r["state"] for r in req.reports()]
    assert states == ["pulling", "done"]
    assert req.reports()[-1]["message"] == "Updated to version 1.1.0." and req.reports()[-1]["current_version"] == "1.1.0"
    assert "up -d --no-build --pull never" in req.log.read_text()


def test_an_update_request_with_nothing_newer_reports_done_without_restarting(req):
    req.poll("update")
    (req.state / "pull_id").write_text("sha256:old")

    _script(req, "requests")

    assert [r["state"] for r in req.reports()] == ["pulling", "done"]
    assert "up -d" not in req.log.read_text()


def test_a_failed_update_is_reported_failed(req):
    req.poll("update")
    (req.state / "pull_fail").touch()

    _script(req, "requests")

    last = req.reports()[-1]
    assert last["state"] == "failed" and "download did not finish" in last["message"]


def test_a_check_request_pulls_and_reports_what_is_available_but_installs_nothing(req):
    req.poll("check")

    _script(req, "requests")

    assert [r["state"] for r in req.reports()] == ["checking", "done"]
    last = req.reports()[-1]
    assert last["available_version"] == "1.1.0" and last["current_version"] == "1.0.0"
    assert (req.state / "id").read_text().strip() == "sha256:old"   # the running image is back under the tag
    assert "up -d" not in req.log.read_text()


def test_a_check_with_nothing_newer_says_so(req):
    req.poll("check")
    (req.state / "pull_id").write_text("sha256:old")

    _script(req, "requests")

    last = req.reports()[-1]
    assert last["state"] == "done" and last["available_version"] is None


def test_a_backup_request_asks_the_provisioner_for_a_full_backup(req):
    req.poll("backup")

    _script(req, "requests")

    assert [r["state"] for r in req.reports()] == ["backing-up", "done"]
    assert req.reports()[-1]["message"] == "instance backup written: /backups/instance/x.zip"
    log = req.log.read_text()
    assert "instance:backup --requested" in log and "pull" not in log


def test_a_failed_backup_is_reported_failed(req):
    req.poll("backup")
    (req.state / "backup_fail").touch()

    _script(req, "requests")

    last = req.reports()[-1]
    assert last["state"] == "failed" and "provisioner is busy" in last["message"]
