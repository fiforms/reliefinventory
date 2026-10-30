"""Backend half of Settings > Local server: a full Relief Inventory server running on this device.

The server itself (Docker, MariaDB, the web app — see ../../LOCAL_SERVER.md) is run by a root
supervisor, slide-announcer-local-server.service. This process holds no Docker access and no
root: it starts one of four on-demand units (the platform's polkit rule lets it start units named
slide-announcer-*.service), reads the supervisor's status file back, and asks the server itself
(over loopback) whether its first-run setup is still pending. Nothing the page sends reaches the
helper as data — enable, disable and reset are three separate units — so there is nothing to validate.
"""
import asyncio
import json
import os
import shutil
import socket
import time
from pathlib import Path

import httpx

ROOT = Path("/opt/relief-inventory")  # the offline-server .deb's payload
DATA = Path("/data/relief-inventory")
RUN = Path("/run/slide-announcer/local-server")
# What the device's confirmed server was before "use as home" replaced it, so "stop using" can put it back.
PREVIOUS_HOME = Path("/data/status/server-url-before-local")
UNIT = "slide-announcer-local-server.service"
ACTION_UNITS = {
    "enable": "slide-announcer-local-server-enable.service",
    "disable": "slide-announcer-local-server-disable.service",
    "reset": "slide-announcer-local-server-reset.service",
    "update": "slide-announcer-local-server-update.service",
}
# Unpacked images (~1.6 GB) plus room to work; the supervisor enforces the same floor (MIN_FREE_BYTES).
NEEDED_BYTES = 3_000_000_000
# Below this the browser, MariaDB and PHP will fight over memory; the page warns, it doesn't forbid.
RECOMMENDED_RAM_BYTES = 3_000_000_000
# Release channels the server can follow: "latest" = official releases, "edge" = newer builds for developers/testers.
# The choice is a file the supervisor script reads (state/channel); see slide-announcer-local-server.
CHANNELS = ("latest", "edge")
# Phases whose status file can be trusted without the supervisor unit running.
SETTLED = {"stopped", "error", "resetting"}
# An update's phases while it is working (the rest — current, updated, failed — are results).
UPDATING = {"checking", "downloading", "installing", "restarting"}


class LocalServerError(Exception):
    """`status` is the HTTP status to answer with; the message is safe to show staff."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


async def _run(*argv: str, timeout: float = 10.0) -> tuple[int | None, str]:
    try:
        proc = await asyncio.create_subprocess_exec(
            *argv, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE)
    except (FileNotFoundError, PermissionError):
        return None, ""
    try:
        out, err = await asyncio.wait_for(proc.communicate(), timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        return None, ""
    return proc.returncode, (out or err).decode(errors="replace")


class LocalServer:
    def __init__(self, root: Path = ROOT, data: Path = DATA, run_dir: Path = RUN, data_mount: Path = Path("/data"),
                 port: int = 8080, previous_home: Path = PREVIOUS_HOME, pairing=None, probe=None):
        self.root, self.data, self.run_dir, self.data_mount, self.port = root, data, run_dir, data_mount, port
        self.previous_home = previous_home
        self._pairing = pairing
        self._probe = probe

    async def probe(self, url: str) -> dict:
        if self._probe is None:
            import server_check  # platform module; late for the same reason as pairing
            self._probe = server_check.probe
        return await self._probe(url)

    @property
    def pairing(self):
        if self._pairing is None:
            import pairing  # the platform's pairing module; imported late so tests can run without it
            self._pairing = pairing
        return self._pairing

    # --- "home": whether this device's own kiosk and print server use the local server as their site

    @property
    def home_url(self) -> str:
        return f"http://localhost:{self.port}"

    def home_state(self) -> dict:
        try:
            current = self.pairing.read_confirmed_server_url()
            return {"is_home": current == self.home_url, "paired": self.pairing.is_paired(), "site_url": current}
        except Exception:  # the platform module is unavailable (tests) or /data is unreadable
            return {"is_home": False, "paired": False, "site_url": None}

    def _hostname_change_pending(self, host: str) -> bool:
        """A new device name is saved but only takes effect (and starts being announced as .local) on a reboot."""
        try:
            return bool(self.pairing.hostname_change_pending(host))
        except Exception:
            return False

    def _require_unpaired(self) -> None:
        if self.home_state()["paired"]:
            raise LocalServerError(
                409, "This device is paired with a site. Unpair it in Settings > Pairing (or do a factory reset) before changing its site.")

    async def set_site(self, raw_url: str, verify: bool = True) -> None:
        """Sets an external site as this device's server, from Settings, after the wizard has locked the address.

        Same exception to the platform's lock as set_home(), same condition: refused once the device is paired, because
        the pairing token belongs to the old site. Unpairing (which keeps the address) or a factory reset is what
        opens it up again. An address is checked the way the wizard checks it (`GET {url}/up`) unless `verify` is
        false — the "use it anyway" case for a site that is simply down right now."""
        self._require_unpaired()
        try:
            url = self.pairing.normalize_server_url(raw_url)
        except self.pairing.ServerUrlError as exc:
            raise LocalServerError(422, str(exc))
        if verify:
            result = await self.probe(url)
            if result["state"] != "ok":
                why = f" ({result['detail']})" if result.get("detail") else ""
                raise LocalServerError(422, f"Couldn't reach a server at {result['host']}{why}.")
        self.pairing.write_server_url(url)
        # An explicit choice replaces whatever "Go back" would have restored.
        self.previous_home.unlink(missing_ok=True)

    def set_home(self, enabled: bool) -> None:
        """Points this device's confirmed server at the local server, remembering what it was, or puts that back.

        The platform locks the confirmed address once setup is done (SERVER_URL.md) because moving a device to
        another site invalidates its pairing; this is the one deliberate exception, and only while the device is
        not paired to a site — a paired token belongs to the old server and would silently stop working."""
        self._require_unpaired()
        current = self.pairing.read_confirmed_server_url()
        if enabled:
            if current != self.home_url:
                if current:
                    self.previous_home.parent.mkdir(parents=True, exist_ok=True)
                    self.previous_home.write_text(current + "\n")
                else:
                    self.previous_home.unlink(missing_ok=True)
                self.pairing.write_server_url(self.home_url)
            return
        if current != self.home_url:
            return
        try:
            before = self.previous_home.read_text().strip()
        except OSError:
            before = ""
        if before:
            self.pairing.write_server_url(before)
        else:
            self.pairing.SERVER_URL_FILE.unlink(missing_ok=True)   # back to the boot yaml's default, if it has one
        self.previous_home.unlink(missing_ok=True)

    # --- what is there

    def available(self) -> bool:
        """The image was built with the offline-server package (rstation/build-offline-bundle.sh)."""
        return (self.root / "images.tar").is_file() and (self.root / "compose.yaml").is_file()

    def enabled(self) -> bool:
        return (self.data / "enabled").exists()

    def _read_status(self) -> dict:
        try:
            data = json.loads((self.run_dir / "status.json").read_text())
        except (OSError, ValueError):
            return {}
        return data if isinstance(data, dict) else {}

    def _app_manifest(self) -> dict | None:
        try:
            data = json.loads((self.root / "manifest.json").read_text())
        except (OSError, ValueError):
            return None
        return data if isinstance(data, dict) else None

    def _read_json(self, path: Path) -> dict | None:
        try:
            data = json.loads(path.read_text())
        except (OSError, ValueError):
            return None
        return data if isinstance(data, dict) else None

    def channel(self) -> str:
        try:
            value = (self.data / "state" / "channel").read_text().strip()
        except OSError:
            value = ""
        return value if value in CHANNELS else "latest"

    def set_channel(self, channel: str) -> None:
        """Chooses which image the next update pulls. Nothing changes until someone runs an update."""
        if channel not in CHANNELS:
            raise LocalServerError(400, "Unknown channel.")
        state = self.data / "state"
        state.mkdir(parents=True, exist_ok=True)
        (state / "channel").write_text(channel + "\n")

    def _installed_version(self) -> str | None:
        """The release the stack runs: what the supervisor recorded (it asks Docker), else what was baked in."""
        try:
            recorded = (self.data / "state" / "app-version").read_text().strip()
        except OSError:
            recorded = ""
        baked = (self._app_manifest() or {}).get("app_version") or ""
        return (recorded or baked).lstrip("v") or None

    async def _update_state(self, available: bool) -> dict | None:
        """The last (or current) update, as the update script wrote it; an in-progress one whose unit is gone was interrupted."""
        raw = self._read_json(self.data / "state" / "update.json") if available else None
        if not raw:
            return None
        if raw.get("phase") in UPDATING and not await self._unit_active(ACTION_UNITS["update"]):
            raw = {**raw, "phase": "failed", "message": "The update was interrupted (the device may have restarted). Try again."}
        return {k: raw.get(k) for k in ("phase", "message", "from", "to", "updated")}

    def _backups(self) -> list[dict]:
        try:
            files = sorted((p for p in (self.data / "backups").iterdir() if p.is_file()),
                           key=lambda p: p.stat().st_mtime, reverse=True)
            return [{"name": p.name, "bytes": p.stat().st_size, "modified": int(p.stat().st_mtime)} for p in files[:10]]
        except OSError:
            return []

    def _free_bytes(self) -> int | None:
        try:
            return shutil.disk_usage(self.data_mount).free
        except OSError:
            return None

    @staticmethod
    def _ram_bytes() -> int | None:
        try:
            for line in Path("/proc/meminfo").read_text().splitlines():
                if line.startswith("MemTotal:"):
                    return int(line.split()[1]) * 1024
        except (OSError, ValueError, IndexError):
            pass
        return None

    async def _addresses(self) -> list[str]:
        """This device's LAN addresses — what other people's browsers type. Skips loopback and Docker's own bridges."""
        code, out = await _run("ip", "-j", "-4", "addr")
        try:
            links = json.loads(out) if code == 0 else []
        except ValueError:
            links = []
        found = []
        for link in links:
            name = link.get("ifname", "")
            if name == "lo" or name.startswith(("docker", "br-", "veth")):
                continue
            found += [a["local"] for a in link.get("addr_info", []) if a.get("family") == "inet" and a.get("local")]
        return found

    async def _unit_active(self, unit: str) -> bool:
        _, out = await _run("systemctl", "is-active", unit)
        return out.strip() in ("active", "activating", "reloading")

    async def _setup_pending(self) -> bool | None:
        """Whether the server's first-run wizard (/install) is still open; None if it can't be asked."""
        try:
            async with httpx.AsyncClient(timeout=3.0, follow_redirects=False) as client:
                response = await client.get(f"http://127.0.0.1:{self.port}/install")
        except httpx.HTTPError:
            return None
        return True if response.status_code == 200 else False if response.is_redirect else None

    # --- the page's data

    async def status(self) -> dict:
        available = self.available()
        raw = self._read_status()
        supervisor = await self._unit_active(UNIT) if available else False
        resetting = await self._unit_active(ACTION_UNITS["reset"]) if available else False
        phase = raw.get("phase") or "stopped"
        message = raw.get("message") or ""
        if resetting:
            phase, message = "resetting", "Erasing the server's data…"
        elif not supervisor and phase not in SETTLED:
            # The status file is from before a reboot or a crash; the unit is the truth.
            phase, message = "stopped", ""

        update = await self._update_state(available)
        update_busy = bool(update and update["phase"] in UPDATING)
        up = phase in ("running", "starting", "degraded") and supervisor
        pending = await self._setup_pending() if phase == "running" else None
        host = socket.gethostname()
        free = self._free_bytes()
        ram = self._ram_bytes()
        loaded = (self.data / "state" / "loaded-image-id").is_file()
        return {
            "available": available,
            "enabled": self.enabled(),
            "phase": phase,
            "message": message,
            "running": phase == "running",
            "busy": phase in ("starting", "loading-images", "stopping", "resetting"),
            "port": self.port,
            "local_url": f"http://localhost:{self.port}/",
            "hostname_url": f"http://{host}.local:{self.port}/",
            "addresses": [f"http://{a}:{self.port}/" for a in await self._addresses()] if up else [],
            "setup_pending": pending,
            # Only worth showing while somebody still has to complete the wizard with it.
            **self.home_state(),
            "hostname": host,
            "hostname_change_pending": self._hostname_change_pending(host),
            "setup_code": (raw.get("setup_code") or None) if pending else None,
            "app": self._app_manifest(),
            "app_version": self._installed_version(),
            "channel": self.channel(),
            "update": update,
            "update_busy": update_busy,
            # Docker only runs while the server is started, and an update swaps the image under a live stack.
            "can_update": available and phase in ("running", "degraded") and not update_busy,
            "backups": self._backups(),
            "backup_dir": str(self.data / "backups"),
            "free_bytes": free,
            "needed_bytes": NEEDED_BYTES,
            "low_space": free is not None and not loaded and free < NEEDED_BYTES,
            "ram_bytes": ram,
            "low_memory": ram is not None and ram < RECOMMENDED_RAM_BYTES,
            "updated": raw.get("updated"),
            "now": int(time.time()),
        }

    # --- actions

    async def act(self, action: str) -> None:
        if action not in ACTION_UNITS:
            raise LocalServerError(400, "Unknown action.")
        if not self.available():
            raise LocalServerError(409, "This device's software does not include the local server.")
        status = await self.status()
        if action == "reset" and status["busy"] and status["phase"] == "resetting":
            raise LocalServerError(409, "The server's data is already being erased.")
        if action == "update":
            if status["update_busy"]:
                raise LocalServerError(409, "An update is already in progress.")
            if not status["can_update"]:
                raise LocalServerError(409, "Start the local server and wait for it to be running, then update.")
        elif status["update_busy"]:
            raise LocalServerError(409, "An update is in progress. Wait for it to finish before stopping or erasing the server.")
        # --no-block: starting the server, and above all stopping it (the database gets a minute to close
        # cleanly) outlasts any reasonable HTTP request; the page polls the status instead.
        code, out = await _run("systemctl", "start", "--no-block", ACTION_UNITS[action], timeout=20)
        if code != 0:
            raise LocalServerError(502, out.strip() or "The device could not carry that out. Try again, or restart the device.")
