"""Backend half of "find / add / remove a printer" (Settings > Printers).

CUPS only lets an administrator discover devices and add or delete queues, and this
process neither holds a CUPS credential nor runs as root. So — like admin_password.py —
it drops a request file in a tmpfs directory it owns, starts
slide-announcer-printer-setup.service (the platform's polkit rule lets it start units
with that prefix) and reads the answer back. What is acceptable (queue-name shape, URI
schemes, never overwriting an existing queue) is enforced by the root helper
(image/system/scripts/slide-announcer-printer-setup), not here.

Queues are driverless (IPP Everywhere) unless staff pick a driver: one of the generic
ones (PostScript, PCL) or any model-specific driver installed in the image, found by
searching the driver list. Vendor-supplied drivers that aren't packaged (e.g. Brother's
own) still go through the CUPS page behind "Advanced".

This module also turns the helper's raw device list into something staff can pick from
(`curate()`): CUPS reports one entry per protocol per printer, plus generic placeholders.
"""
import asyncio
import json
import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import unquote, urlsplit

EXCHANGE_DIR = Path("/run/slide-announcer/printer-setup")
UNIT = "slide-announcer-printer-setup.service"
# The helper's own limits are 15 s of discovery and 100 s for an add; this just stops the backend waiting forever.
TIMEOUT_SECONDS = {"discover": 75, "add": 125, "remove": 40, "drivers": 100}

# The built-in driver choices; the helper also accepts any driver id it listed itself (see `drivers`).
DRIVERS = ("everywhere", "generic-postscript", "generic-pcl", "zebra-zpl")

# How a discovered connection is reached. CUPS lists one entry per Bonjour *service* too
# (`..._ipp._tcp`, `..._pdl-datastream._tcp`, `..._printer._tcp`), and only the IPP ones
# can be driverless — a printer that advertises only raw port 9100 (many older lasers,
# e.g. Brother MFC-9970CDW) needs a driver.
_DNSSD_SERVICES = {"_ipp": "ipp", "_ipps": "ipp", "_ipp-tls": "ipp", "_pdl-datastream": "raw", "_printer": "lpd"}
_FAMILY_LABELS = {"ipp": "Network (IPP)", "raw": "Network (raw port 9100)", "lpd": "Network (LPD)", "usb": "USB"}
# Best first: IPP before raw before LPD; within a family, Bonjour (follows the printer if its
# address changes) before a fixed address.
_RANKS = ("dnssd/ipp", "ipps", "ipp", "dnssd/raw", "socket", "dnssd/lpd", "lpd", "usb")


class SetupError(Exception):
    """`status` is the HTTP status to answer with; the message is safe to show staff."""

    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


@dataclass
class Candidate:
    """One printer staff can add."""

    name: str  # a suggested queue name, already valid and not in use
    model: str
    uri: str  # the connection to use
    kind: str  # "network" | "usb"
    connection: str  # human label of how it is reached
    driverless: bool  # reached over IPP, so IPP Everywhere may work; False means "expect to choose a driver"
    location: str | None = None
    added: bool = False  # already a CUPS queue
    other_uris: list = field(default_factory=list)  # the same printer's other connections, kept for matching "added"


async def _start_unit() -> tuple[int, str]:
    proc = await asyncio.create_subprocess_exec(
        "systemctl", "start", UNIT, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE)
    stderr = b""
    try:
        _, stderr = await asyncio.wait_for(proc.communicate(), timeout=max(TIMEOUT_SECONDS.values()))
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        return 1, "timed out"
    return proc.returncode, stderr.decode(errors="replace").strip()


class PrinterSetup:
    def __init__(self, exchange_dir: Path = EXCHANGE_DIR, start_unit=_start_unit):
        self.dir = exchange_dir
        self._start_unit = start_unit
        self._lock = asyncio.Lock()

    async def discover(self, existing_uris: set | None = None, existing_names: set | None = None) -> list[Candidate]:
        result = await self._ask({"op": "discover"})
        self._raise_unless_ok(result, "Could not search for printers.")
        return curate(result.get("devices") or [], existing_uris or set(), existing_names or set())

    async def add(self, name: str, uri: str, driver: str = "everywhere") -> None:
        result = await self._ask({"op": "add", "name": name, "uri": uri, "driver": driver})
        self._raise_unless_ok(result, "Could not add the printer.")

    async def drivers(self, query: str) -> dict:
        """Installed drivers matching every word of `query`: {"drivers": [{"id", "name"}], "total": n}."""
        result = await self._ask({"op": "drivers", "query": query})
        self._raise_unless_ok(result, "Could not search the driver list.")
        return {"drivers": result.get("drivers") or [], "total": result.get("total", 0)}

    async def cups_login(self) -> str:
        """A URL that opens CUPS's admin page already signed in (a fresh one-time password each call)."""
        result = await self._ask({"op": "cups-login"})
        self._raise_unless_ok(result, "Could not prepare the printer setup login.")
        user, password = result.get("user"), result.get("password")
        if not (isinstance(user, str) and isinstance(password, str) and re.fullmatch(r"[A-Za-z0-9_-]+", user + password)):
            raise SetupError(500, "Could not prepare the printer setup login.")
        return f"http://{user}:{password}@localhost:631/admin"

    async def remove(self, name: str) -> None:
        result = await self._ask({"op": "remove", "name": name})
        self._raise_unless_ok(result, "Could not remove the printer.")

    @staticmethod
    def _raise_unless_ok(result: dict, fallback: str) -> None:
        code = result.get("code")
        if code == "ok":
            return
        detail = result.get("detail")
        if code == "invalid_name":
            raise SetupError(400, "That printer name isn't allowed. Use letters, numbers, dashes and underscores.")
        if code == "invalid_request":
            raise SetupError(400, "Enter at least one word to search for.")
        if code == "invalid_driver":
            raise SetupError(400, "That driver choice isn't available.")
        if code == "invalid_uri":
            raise SetupError(400, "That printer address isn't valid.")
        if code == "exists":
            raise SetupError(409, "A printer with that name already exists.")
        if code == "not_found":
            raise SetupError(404, "That printer no longer exists.")
        if code == "add_failed":
            raise SetupError(422, "This printer couldn't be set up with that driver — try another, or check that it is switched on and reachable."
                             + (f" ({detail})" if detail else ""))
        raise SetupError(500, fallback + (f" ({detail})" if detail else ""))

    async def _ask(self, request: dict) -> dict:
        async with self._lock:
            self._prepare()
            self._write("request", json.dumps(request))
            try:
                await self._start_unit()
            finally:
                self._remove("request")  # the helper deletes it too; belt and braces if it never ran
            result = self._read_result()
        if result is None:
            # No answer at all: the unit didn't run (not installed, polkit rule missing, timed out, ...).
            raise SetupError(502, "The device could not manage printers. Try again, or restart the device.")
        return result

    # --- the exchange directory (tmpfs; the helper treats everything in it as untrusted)

    def _prepare(self) -> None:
        try:
            self.dir.mkdir(mode=0o700, parents=False, exist_ok=True)
        except OSError as exc:
            raise SetupError(502, f"The printer setup service is not available ({exc.strerror}).")
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


# --- turning CUPS's device list into printers

def queue_name(text: str, taken: set) -> str:
    """A valid, unused CUPS queue name from free text (make and model, usually)."""
    base = re.sub(r"[^A-Za-z0-9_.-]+", "_", text).strip("_.-")[:50] or "Printer"
    candidate, n = base, 2
    while candidate.lower() in {t.lower() for t in taken}:
        candidate = f"{base}_{n}"
        n += 1
    return candidate


def _scheme(uri: str) -> str:
    return urlsplit(uri).scheme


def _host(uri: str) -> str:
    return (urlsplit(uri).hostname or "").lower()


def _dnssd_parts(uri: str) -> tuple[str, str]:
    """(instance name, service family) of a dnssd URI: dnssd://Brother%20X._ipp._tcp.local/ -> ("Brother X", "ipp")."""
    host = unquote(urlsplit(uri).netloc)
    instance, _, rest = host.partition("._")
    service = "_" + rest.partition("._")[0] if rest else ""
    return instance, _DNSSD_SERVICES.get(service, "raw")


def _key(uri: str) -> str:
    """Where a connection ranks (see _RANKS)."""
    scheme = _scheme(uri)
    return f"dnssd/{_dnssd_parts(uri)[1]}" if scheme == "dnssd" else scheme


def _family(uri: str) -> str:
    scheme = _scheme(uri)
    if scheme == "dnssd":
        return _dnssd_parts(uri)[1]
    return {"ipp": "ipp", "ipps": "ipp", "socket": "raw", "lpd": "lpd", "usb": "usb"}.get(scheme, "raw")


def _serial(device_id: str) -> str:
    """The SN (or SERIALNUMBER) field of an IEEE 1284 device id, which tells two identical printers apart."""
    for part in device_id.split(";"):
        name, _, value = part.partition(":")
        if name.strip().upper() in ("SN", "SERIALNUMBER"):
            return value.strip()
    return ""


def _usable(device: dict) -> bool:
    """A real, addable device — not a generic placeholder such as "Internet Printing Protocol (ipp)"."""
    uri = device.get("uri") or ""
    if device.get("class") not in ("network", "direct") or "://" not in uri or _scheme(uri) not in ("dnssd", "ipps", "ipp", "socket", "lpd", "usb"):
        return False
    return (device.get("make-and-model") or "").strip().lower() not in ("", "unknown")


def _rank(device: dict) -> int:
    key = _key(device["uri"])
    return _RANKS.index(key) if key in _RANKS else len(_RANKS)


def curate(devices: list, existing_uris: set, existing_names: set) -> list[Candidate]:
    """One entry per physical printer, with its best connection.

    CUPS lists the same printer once per protocol and, for Bonjour, once per service.
    Entries are grouped by make and model — and by serial number when the printer
    reports one, so two identical printers stay two. Within a group, Bonjour entries are
    told apart by their instance name (the printer's Bonjour name): several instances are
    several printers, each kept separately with the socket/LPD entries dropped as
    ambiguous alternates; one instance (or none) means everything in the group is the one
    printer, or — with no Bonjour at all — one printer per address. USB devices are never
    merged with network ones.
    """
    usable = sorted((d for d in devices if isinstance(d, dict) and _usable(d)), key=_rank)
    taken = set(existing_names)
    candidates: list[Candidate] = []

    def make(device, alternates):
        model = (device.get("make-and-model") or "").strip()
        uris = [device["uri"], *(a["uri"] for a in alternates)]
        family = _family(device["uri"])
        candidate = Candidate(
            name=queue_name(model, taken), model=model, uri=device["uri"],
            kind="usb" if family == "usb" else "network", connection=_FAMILY_LABELS.get(family, "Network"),
            driverless=any(_family(u) == "ipp" for u in uris),
            location=(device.get("location") or "").strip() or None, other_uris=uris[1:])
        candidate.added = any(u in existing_uris for u in uris)
        taken.add(candidate.name)
        candidates.append(candidate)

    groups: dict = {}
    for device in usable:
        if _scheme(device["uri"]) == "usb":
            make(device, [])
            continue
        key = (device["make-and-model"].strip().lower(), _serial(device.get("device-id") or ""))
        groups.setdefault(key, []).append(device)

    for group in groups.values():  # each group is already best-connection-first
        bonjour: dict = {}
        for device in group:
            if _scheme(device["uri"]) == "dnssd":
                bonjour.setdefault(_dnssd_parts(device["uri"])[0].lower(), []).append(device)
        if len(bonjour) > 1:
            for same_printer in bonjour.values():
                make(same_printer[0], same_printer[1:])
        elif bonjour:
            make(group[0], group[1:])
        else:
            by_host: dict = {}
            for device in group:
                by_host.setdefault(_host(device["uri"]) or device["uri"], []).append(device)
            for same_host in by_host.values():
                make(same_host[0], same_host[1:])

    return sorted(candidates, key=lambda c: (c.added, c.model.lower(), c.uri))


def display_uri(uri: str) -> str:
    """The connection for people: dnssd names arrive percent-encoded."""
    return unquote(uri)


def address_to_uri(address: str) -> str:
    """A host name or IP typed by staff becomes the standard driverless URI; a full URI is kept as is.

    Raises ValueError for anything that isn't a plain host (the helper validates the final URI again)."""
    text = (address or "").strip()
    if not text:
        raise ValueError("Enter the printer's address.")
    if "://" in text:
        return text
    if not re.fullmatch(r"[A-Za-z0-9._:\[\]-]{1,253}(/[A-Za-z0-9._~/-]*)?", text):
        raise ValueError("That doesn't look like a printer address.")
    host, slash, path = text.partition("/")
    return f"ipp://{host}{slash + path if slash else '/ipp/print'}"
