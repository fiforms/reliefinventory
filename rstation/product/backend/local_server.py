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
}
# Unpacked images (~1.6 GB) plus room to work; the supervisor enforces the same floor (MIN_FREE_BYTES).
NEEDED_BYTES = 3_000_000_000
# Below this the browser, MariaDB and PHP will fight over memory; the page warns, it doesn't forbid.
RECOMMENDED_RAM_BYTES = 3_000_000_000
# Phases whose status file can be trusted without the supervisor unit running.
SETTLED = {"stopped", "error", "resetting"}


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
                 port: int = 8080, previous_home: Path = PREVIOUS_HOME, pairing=None):
        self.root, self.data, self.run_dir, self.data_mount, self.port = root, data, run_dir, data_mount, port
        self.previous_home = previous_home
        self._pairing = pairing

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
            return {"is_home": current == self.home_url, "paired": self.pairing.is_paired()}
        except Exception:  # the platform module is unavailable (tests) or /data is unreadable
            return {"is_home": False, "paired": False}

    def _hostname_change_pending(self, host: str) -> bool:
        """A new device name is saved but only takes effect (and starts being announced as .local) on a reboot."""
        try:
            return bool(self.pairing.hostname_change_pending(host))
        except Exception:
            return False

    def set_home(self, enabled: bool) -> None:
        """Points this device's confirmed server at the local server, remembering what it was, or puts that back.

        The platform locks the confirmed address once setup is done (SERVER_URL.md) because moving a device to
        another site invalidates its pairing; this is the one deliberate exception, and only while the device is
        not paired to a site — a paired token belongs to the old server and would silently stop working."""
        state = self.home_state()
        if state["paired"]:
            raise LocalServerError(409, "This device is paired with a site. Remove it from that site's R Station Devices page first.")
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
        # --no-block: starting the server, and above all stopping it (the database gets a minute to close
        # cleanly) outlasts any reasonable HTTP request; the page polls the status instead.
        code, out = await _run("systemctl", "start", "--no-block", ACTION_UNITS[action], timeout=20)
        if code != 0:
            raise LocalServerError(502, out.strip() or "The device could not carry that out. Try again, or restart the device.")
