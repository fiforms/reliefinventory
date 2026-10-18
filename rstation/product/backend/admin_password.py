"""Backend half of "set / change the printer-setup password" (Settings > Printers).

The password is slideadmin's — the same login the CUPS web interface asks for
(image/system/scripts/rstation-printer-admin puts that account in lpadmin). This
process never touches it directly and never runs as root: it drops a request
file in a tmpfs directory it owns, starts slide-announcer-admin-password.service
(the platform's polkit rule lets it start units with that prefix), and reads the
answer back. The rules — first password needs no old one, changing it does,
wrong guesses are throttled — are enforced by the root helper
(image/system/scripts/slide-announcer-admin-password), not here, so nothing in
this process can skip them. See that script's docstring for the protocol.

Passwords are never logged, and never leave this process except into that file.
"""
import asyncio
import json
import os
from pathlib import Path

EXCHANGE_DIR = Path("/run/slide-announcer/admin-password")
MARKER = Path("/etc/rstation/admin-password-set")
UNIT = "slide-announcer-admin-password.service"
TIMEOUT_SECONDS = 40  # the helper's own delay on a wrong guess is a few seconds

MIN_LENGTH, MAX_LENGTH = 8, 128  # the helper re-checks; this just answers faster


class PasswordError(Exception):
    """`status` is the HTTP status to answer with; the message is safe to show staff."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


async def _start_unit() -> tuple[int, str]:
    proc = await asyncio.create_subprocess_exec(
        "systemctl", "start", UNIT, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE)
    try:
        _, stderr = await asyncio.wait_for(proc.communicate(), timeout=TIMEOUT_SECONDS)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        return 1, "timed out"
    return proc.returncode, stderr.decode(errors="replace").strip()


class AdminPassword:
    def __init__(self, exchange_dir: Path = EXCHANGE_DIR, marker: Path = MARKER, start_unit=_start_unit):
        self.dir = exchange_dir
        self.marker = marker
        self._start_unit = start_unit
        self._lock = asyncio.Lock()

    def is_set(self) -> bool:
        """False on a fresh or factory-reset device: the next password needs no old one."""
        return self.marker.exists()

    async def change(self, old: str | None, new: str) -> None:
        """Sets (first run) or changes the password; raises PasswordError otherwise."""
        if not isinstance(new, str) or not (MIN_LENGTH <= len(new) <= MAX_LENGTH):
            raise PasswordError(400, f"The password must be {MIN_LENGTH} to {MAX_LENGTH} characters.")
        async with self._lock:
            self._prepare()
            self._write("request", json.dumps({"old": old, "new": new}))
            try:
                returncode, _ = await self._start_unit()
            finally:
                self._remove("request")  # the helper deletes it too; belt and braces if it never ran
            result = self._read_result()
        if result is None:
            # No answer at all: the unit didn't run (not installed, polkit rule missing, ...).
            raise PasswordError(502, "The device could not change the password. Try again, or restart the device.")
        code = result.get("code")
        if code == "ok":
            return
        if code == "wrong_password":
            raise PasswordError(403, "The current password is not correct.")
        if code == "locked":
            minutes = max(1, -(-int(result.get("retry_after") or 300) // 60))
            raise PasswordError(429, f"Too many wrong passwords. Try again in {minutes} minute{'s' if minutes != 1 else ''}.")
        if code == "invalid_length":
            raise PasswordError(400, f"The password must be {MIN_LENGTH} to {MAX_LENGTH} characters.")
        if code == "invalid_chars":
            raise PasswordError(400, "The password can't contain control characters.")
        raise PasswordError(500, "The device could not change the password.")

    # --- the exchange directory (tmpfs; the helper treats everything in it as untrusted)

    def _prepare(self) -> None:
        try:
            self.dir.mkdir(mode=0o700, parents=False, exist_ok=True)
        except OSError as exc:
            raise PasswordError(502, f"The password service is not available ({exc.strerror}).")
        self._remove("result")  # never read a stale answer

    def _write(self, name: str, content: str) -> None:
        self._remove(name)
        fd = os.open(self.dir / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w") as f:
            f.write(content)

    def _remove(self, name: str) -> None:
        try:
            (self.dir / name).unlink()
        except OSError:
            pass

    def _read_result(self) -> dict | None:
        try:
            data = json.loads((self.dir / "result").read_text())
        except (OSError, ValueError):
            return None
        finally:
            self._remove("result")
        return data if isinstance(data, dict) else None
