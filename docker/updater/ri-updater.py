#!/usr/bin/env python3
"""Relief Inventory host-side updater.

Runs on the Docker host, outside the containers (the app cannot run `docker compose` itself). Polls the app for a
requested "check" or "update" and reports each step back, authenticating with the shared secret RI_UPDATE_SECRET that
is set in compose.yaml. The secret (and nothing else) is read from the compose file through `docker compose config`,
so compose.yaml stays the one place it lives.

  check   `docker compose pull`, then compare the pulled image's RI_VERSION with the running one. Nothing restarts.
  update  pull; if the image is newer, `docker compose down` then `docker compose up -d`, wait for /up, report.

Never goes backwards (an image whose RI_VERSION is not newer, or has none, is not installed), and never uses
`down -v`. The pre-upgrade database backup is taken by the new container's entrypoint, not here.

Standard library only. Protocol: App\\Models\\Control\\InstanceUpdate in the app; GET /api/instance/update and
POST /api/instance/update/status with `Authorization: Bearer <secret>`.
"""
import argparse
import fcntl
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

REPORT_RETRY_SECONDS = 600   # the app is down while it restarts: keep trying to say how it went
HEALTH_WAIT_SECONDS = 600    # a migration after an upgrade can take a while
SERVICE = "app"


def log(msg):
    print(f"ri-updater: {msg}", flush=True)


def run(cmd, timeout=1800):
    """Every external command goes through here, so tests can fake docker."""
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    return p.returncode, p.stdout, p.stderr


def version_key(v):
    """Release versions like 1.4.0 or v1.4.0-rc1 -> comparable tuple, or None when not a version."""
    m = re.match(r"^v?(\d+)\.(\d+)\.(\d+)", v or "")
    return tuple(int(x) for x in m.groups()) if m else None


class Updater:
    def __init__(self, compose_file, url, runner=run, sleep=time.sleep):
        self.compose_file = compose_file
        self.url = url.rstrip("/")
        self.run = runner
        self.sleep = sleep
        self.secret = None

    # --- docker ---------------------------------------------------------------------------------------------
    def compose(self, *args, timeout=1800):
        return self.run(["docker", "compose", "-f", self.compose_file, *args], timeout=timeout)

    def load_secret(self):
        code, out, err = self.compose("config", "--format", "json", timeout=60)
        if code != 0:
            raise RuntimeError(f"docker compose config failed: {err.strip()[-300:]}")
        env = json.loads(out)["services"][SERVICE].get("environment") or {}
        self.secret = env.get("RI_UPDATE_SECRET") or None
        return self.secret

    def image_ref(self):
        code, out, _ = self.compose("config", "--format", "json", timeout=60)
        return json.loads(out)["services"][SERVICE]["image"] if code == 0 else None

    def image_info(self, ref):
        """(image id, RI_VERSION) of an image, or (None, None) when it is not there."""
        code, out, _ = self.run(["docker", "image", "inspect", ref, "--format", "{{.Id}}|{{json .Config.Env}}"], timeout=60)
        if code != 0 or "|" not in out:
            return None, None
        image_id, env = out.strip().split("|", 1)
        version = next((e.split("=", 1)[1] for e in json.loads(env or "[]") if e.startswith("RI_VERSION=")), None)
        return image_id, version or None

    def running_image_id(self):
        code, out, _ = self.compose("ps", "-q", SERVICE, timeout=60)
        cid = out.strip().splitlines()[0] if code == 0 and out.strip() else None
        if not cid:
            return None
        code, out, _ = self.run(["docker", "inspect", cid, "--format", "{{.Image}}"], timeout=60)
        return out.strip() if code == 0 and out.strip() else None

    # --- the app --------------------------------------------------------------------------------------------
    def call(self, method, path, body=None):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(self.url + path, data=data, method=method, headers={
            "Authorization": f"Bearer {self.secret}", "Content-Type": "application/json", "Accept": "application/json",
        })
        with urllib.request.urlopen(req, timeout=15) as r:
            return json.loads(r.read() or b"{}")

    def report(self, state, message=None, current=None, available=None, retry=False):
        body = {"state": state, "message": (message or "")[:1000] or None, "current_version": current, "available_version": available}
        deadline = time.monotonic() + (REPORT_RETRY_SECONDS if retry else 0)
        while True:
            try:
                self.call("POST", "/api/instance/update/status", body)
                return True
            except (urllib.error.URLError, OSError, ValueError) as e:
                if time.monotonic() >= deadline:
                    log(f"could not report {state}: {e}")
                    return False
                self.sleep(5)

    def wait_healthy(self):
        deadline = time.monotonic() + HEALTH_WAIT_SECONDS
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(self.url + "/up", timeout=5) as r:
                    if r.status == 200:
                        return True
            except (urllib.error.URLError, OSError):
                pass
            self.sleep(5)
        return False

    # --- the two jobs ---------------------------------------------------------------------------------------
    def pull(self):
        code, _, err = self.compose("pull")
        if code != 0:
            raise RuntimeError("docker compose pull failed: " + err.strip()[-300:])

    def check(self):
        """Pull, then say what is available. Returns (new image id, new version, running version, newer?)."""
        ref = self.image_ref()
        running_id = self.running_image_id()
        _, running_version = self.image_info(running_id) if running_id else (None, None)
        self.pull()
        new_id, new_version = self.image_info(ref)
        newer = bool(new_id) and new_id != running_id and version_key(new_version) is not None and (
            version_key(running_version) is None or version_key(new_version) > version_key(running_version))
        return new_id, new_version, running_version, newer

    def do_check(self):
        self.report("checking", "Looking for a new image")
        try:
            _, new_version, running_version, newer = self.check()
        except Exception as e:  # noqa: BLE001 - every failure is reported, none may kill the loop
            return self.report("failed", str(e), retry=True)
        if newer:
            return self.report("done", f"Version {new_version} is available.", current=running_version, available=new_version, retry=True)
        return self.report("done", "You are running the newest version.", current=running_version, retry=True)

    def do_update(self):
        self.report("pulling", "Downloading the new image")
        try:
            _, new_version, running_version, newer = self.check()
            if not newer:
                return self.report("done", "Already running the newest version.", current=running_version, retry=True)
            self.report("restarting", f"Installing {new_version}; the site is offline for a few minutes")
            for step in (("down",), ("up", "-d")):
                code, _, err = self.compose(*step)
                if code != 0:
                    raise RuntimeError(f"docker compose {step[0]} failed: {err.strip()[-300:]}")
            if not self.wait_healthy():
                raise RuntimeError("The new version did not become healthy in time; check `docker compose logs app`.")
        except Exception as e:  # noqa: BLE001
            return self.report("failed", str(e), retry=True)
        return self.report("done", f"Updated to {new_version}.", current=new_version, retry=True)

    # --- the loop -------------------------------------------------------------------------------------------
    def poll_once(self):
        try:
            action = self.call("GET", "/api/instance/update").get("action")
        except (urllib.error.URLError, OSError, ValueError) as e:
            log(f"app not reachable: {e}")
            return None
        if action == "check":
            self.do_check()
        elif action == "update":
            self.do_update()
        return action

    def loop(self, interval):
        while True:
            try:
                if not self.secret and not self.load_secret():
                    log("RI_UPDATE_SECRET is not set in the compose file; waiting")
                else:
                    self.poll_once()
            except Exception as e:  # noqa: BLE001
                log(f"error: {e}")
            time.sleep(interval)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--compose-file", default=os.environ.get("RI_COMPOSE_FILE", "compose.yaml"))
    ap.add_argument("--url", default=os.environ.get("RI_URL", "http://127.0.0.1:8080"))
    ap.add_argument("--interval", type=int, default=int(os.environ.get("RI_UPDATE_INTERVAL", "30")))
    ap.add_argument("--lock-file", default=os.environ.get("RI_UPDATER_LOCK", "/tmp/ri-updater.lock"))
    ap.add_argument("--once", action="store_true", help="poll once and exit (for testing)")
    a = ap.parse_args()

    lock = open(a.lock_file, "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        log("another updater is already running")
        return 1
    u = Updater(os.path.abspath(a.compose_file), a.url)
    if a.once:
        if not u.load_secret():
            log("RI_UPDATE_SECRET is not set in the compose file")
            return 1
        u.poll_once()
        return 0
    u.loop(a.interval)


if __name__ == "__main__":
    sys.exit(main())
