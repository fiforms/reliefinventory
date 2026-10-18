"""The print server's view of CUPS: list queues and their state, submit a PDF,
follow the job, cancel it — over IPP to the local scheduler (see ipp.py for
why not `lp`/`lpstat`). All calls are async (httpx), so they run on the
backend's event loop without blocking the heartbeat or the kiosk UI.

Printer state is reported to the website as one of `idle | printing | error |
offline` plus a human-readable message (docs: rstation/PRINT_CLIENT_SPEC.md
section 2). classify_printer() is the single place CUPS' state and reasons
become that — keep it conservative: a printer is only called an error when
CUPS says it is one.
"""
from dataclasses import dataclass, field
from urllib.parse import quote

import httpx

from . import ipp

DEFAULT_URL = "http://localhost:631"
# CUPS identifies who owns a job by this name. Cancel-Job must use the same
# one, so the print server can cancel only what it submitted.
USER = "relief-print"

IDLE, PRINTING, ERROR, OFFLINE = "idle", "printing", "error", "offline"

# printer-state-reasons keywords, with the -error/-warning/-report suffix
# stripped (RFC 8011 5.4.12 and the CUPS additions).
_PROBLEMS = {
    "media-empty": "Out of paper or labels",
    "media-needed": "Needs paper or labels",
    "media-jam": "Paper or label jam",
    "toner-empty": "Out of toner",
    "marker-supply-empty": "Out of toner or ink",
    "door-open": "Cover open",
    "cover-open": "Cover open",
    "interlock-open": "Cover open",
    "output-area-full": "Output tray full",
    "output-tray-missing": "Output tray missing",
}
_OFFLINE = {"offline", "timed-out", "shutdown", "paused", "moving-to-paused"}
_SUFFIXES = ("-error", "-warning", "-report")

# What a CUPS network backend says while a job cannot reach its printer. The
# backend retries forever *inside* the job, so the queue never leaves
# `processing`, the reasons stay `none` (or `connecting-to-device`), and
# `lpstat` shows the queue as plain "idle" — the free-text printer-state-message
# is the only signal. Checked against real CUPS 2.4 (socket backend):
#   connection refused -> "The printer may not exist or is unavailable at this time."
#   unresolvable host  -> 'Unable to locate printer "<host>".'
# The rest are from the CUPS backend sources and not yet seen on this hardware.
# Only consulted while a job is being processed, and a message not on this list
# is treated as healthy, so a wrong guess can only miss a problem — never cry wolf.
_UNREACHABLE_MESSAGES = (
    "may not exist or is unavailable",
    "unable to locate printer",
    "printer is not responding",
    "unable to connect",
    "unable to open",
    "waiting for printer to become available",
)


_JOB_ATTRIBUTES = ("job-name", "job-state", "job-state-reasons", "job-state-message",
                   "job-impressions-completed", "job-media-sheets-completed")


class CupsError(Exception):
    """CUPS could not be reached, or refused the request. The message is safe
    to show staff (it ends up in the print queue's error column)."""


@dataclass
class PrinterInfo:
    name: str
    state: str  # idle | printing | error | offline
    message: str | None = None


@dataclass
class JobInfo:
    job_id: int
    state: int  # ipp.JOB_*
    reasons: list = field(default_factory=list)
    message: str | None = None
    name: str | None = None
    # Pages CUPS has finished so far, when the queue's filters report it (a raw
    # queue does not). Lets the print server tell "slow" from "stuck".
    impressions: int | None = None
    sheets: int | None = None

    @property
    def finished(self) -> bool:
        return self.state in (ipp.JOB_CANCELED, ipp.JOB_ABORTED, ipp.JOB_COMPLETED)

    @property
    def completed(self) -> bool:
        return self.state == ipp.JOB_COMPLETED


def _as_list(value) -> list:
    if value is None:
        return []
    return value if isinstance(value, list) else [value]


def _base(reason: str) -> str:
    for suffix in _SUFFIXES:
        if reason.endswith(suffix):
            return reason[: -len(suffix)]
    return reason


def classify_printer(state: int | None, reasons, accepting: bool | None = True, message: str | None = None) -> tuple[str, str | None]:
    """CUPS printer-state + printer-state-reasons -> (idle|printing|error|offline, message).

    * a recognised problem (out of media, jam, cover open, ...) is an `error`
      when it is error-level, or whenever the printer has stopped because of it;
    * a printer that is unreachable or paused is `offline` — including a queue
      that is "processing" while CUPS' own message says it cannot reach the
      printer (see _UNREACHABLE_MESSAGES);
    * `media-low` / `toner-low` style warnings, and `connecting-to-device`
      while a job is being sent, do not make a printer unhealthy;
    * anything else that stopped the queue is an `error` with CUPS' own text.
    """
    reasons = [r for r in _as_list(reasons) if r and r != "none"]
    stopped = state == ipp.PRINTER_STOPPED

    for reason in reasons:
        base = _base(reason)
        if base in _PROBLEMS and (reason.endswith("-error") or stopped):
            return ERROR, _PROBLEMS[base]
    for reason in reasons:
        if _base(reason) in _OFFLINE:
            return OFFLINE, "Printer paused" if _base(reason) in ("paused", "moving-to-paused") else "Printer offline"
    if stopped:
        return ERROR, message or "Printer stopped"
    if accepting is False:
        return OFFLINE, "Printer is not accepting jobs"
    if state == ipp.PRINTER_PROCESSING:
        if message and any(phrase in message.lower() for phrase in _UNREACHABLE_MESSAGES):
            return OFFLINE, f"Printer not reachable: {message.rstrip('.')}"
        return PRINTING, None
    return IDLE, None


class CupsClient:
    def __init__(self, base_url: str = DEFAULT_URL, user: str = USER, transport: httpx.AsyncBaseTransport | None = None):
        self.base_url = base_url.rstrip("/")
        self.user = user
        self._transport = transport
        self._request_id = 0

    async def _call(self, path: str, operation: int, operation_attributes: dict, job_attributes: dict | None = None,
                    document: bytes = b"", timeout: float = 10) -> ipp.IppResponse:
        self._request_id += 1
        body = ipp.encode_request(operation, self._request_id, operation_attributes, job_attributes) + document
        try:
            async with httpx.AsyncClient(timeout=timeout, transport=self._transport) as client:
                response = await client.post(self.base_url + path, content=body, headers={"Content-Type": "application/ipp"})
        except httpx.RequestError as exc:
            raise CupsError(f"Could not reach the print system (CUPS): {exc}") from exc
        if response.status_code != 200:
            raise CupsError(f"The print system (CUPS) answered HTTP {response.status_code}.")
        try:
            return ipp.decode_response(response.content)
        except ipp.IppError as exc:
            raise CupsError(f"Unreadable answer from the print system: {exc}") from exc

    def _printer_path(self, queue: str) -> str:
        return f"/printers/{quote(queue, safe='')}"

    def _printer_uri(self, queue: str) -> ipp.IppValue:
        return ipp.uri(f"ipp://localhost{self._printer_path(queue)}")

    @staticmethod
    def _base_attributes() -> dict:
        return {"attributes-charset": ipp.IppValue(ipp.CHARSET, "utf-8"), "attributes-natural-language": ipp.IppValue(ipp.NATURAL_LANGUAGE, "en")}

    @staticmethod
    def _failure(response: ipp.IppResponse, what: str) -> CupsError:
        operation = response.first(ipp.OPERATION_ATTRIBUTES)
        detail = operation.get("status-message")
        return CupsError(f"{what}: {detail}" if detail else f"{what} (IPP status 0x{response.status:04x}).")

    async def printers(self) -> list[PrinterInfo]:
        """Every queue CUPS knows, already classified."""
        attributes = self._base_attributes()
        attributes["requested-attributes"] = [ipp.keyword(a) for a in (
            "printer-name", "printer-state", "printer-state-reasons", "printer-state-message", "printer-is-accepting-jobs")]
        response = await self._call("/", ipp.CUPS_GET_PRINTERS, attributes)
        if not response.ok:
            # CUPS answers "not-found" when there are no queues at all.
            if response.status == 0x0406:
                return []
            raise self._failure(response, "Could not list printers")
        found = []
        for printer in response.group_attributes(ipp.PRINTER_ATTRIBUTES):
            name = printer.get("printer-name")
            if not name:
                continue
            state, message = classify_printer(
                printer.get("printer-state"), printer.get("printer-state-reasons"),
                printer.get("printer-is-accepting-jobs", True), printer.get("printer-state-message") or None)
            found.append(PrinterInfo(name=name, state=state, message=message))
        return sorted(found, key=lambda p: p.name)

    async def submit(self, queue: str, pdf: bytes, title: str, copies: int = 1, job_options: dict | None = None) -> int:
        """Prints the PDF and returns CUPS' job id. `print-scaling=none` keeps the
        page exactly as rendered (the website already made it the media's size);
        pass job_options to override or add IPP job attributes."""
        operation = self._base_attributes()
        operation["printer-uri"] = self._printer_uri(queue)
        operation["requesting-user-name"] = ipp.name(self.user)
        operation["job-name"] = ipp.name(title[:255])
        operation["document-format"] = ipp.mime("application/pdf")
        job = {"copies": ipp.integer(max(1, int(copies))), "print-scaling": ipp.keyword("none")}
        job.update(job_options or {})
        response = await self._call(self._printer_path(queue), ipp.PRINT_JOB, operation, job, document=pdf, timeout=60)
        if not response.ok:
            raise self._failure(response, f"The print system rejected the job for '{queue}'")
        job_id = response.first(ipp.JOB_ATTRIBUTES).get("job-id")
        if not isinstance(job_id, int):
            raise CupsError("The print system accepted the job but did not say which one it is.")
        return job_id

    async def job(self, queue: str, job_id: int) -> JobInfo | None:
        """Current state of a job, or None if CUPS no longer has it."""
        operation = self._base_attributes()
        operation["printer-uri"] = self._printer_uri(queue)
        operation["job-id"] = ipp.integer(job_id)
        operation["requested-attributes"] = [ipp.keyword(a) for a in _JOB_ATTRIBUTES]
        response = await self._call(self._printer_path(queue), ipp.GET_JOB_ATTRIBUTES, operation)
        if response.status == 0x0406:  # client-error-not-found
            return None
        if not response.ok:
            raise self._failure(response, "Could not read the print job")
        return self._job_info(job_id, response.first(ipp.JOB_ATTRIBUTES))

    @staticmethod
    def _job_info(job_id: int, job: dict) -> JobInfo | None:
        state = job.get("job-state")
        if not isinstance(state, int):
            return None
        reasons = [r for r in _as_list(job.get("job-state-reasons")) if r and r != "none"]
        return JobInfo(
            job_id=job_id, state=state, reasons=reasons, message=job.get("job-state-message") or None,
            name=job.get("job-name"), impressions=job.get("job-impressions-completed"), sheets=job.get("job-media-sheets-completed"))

    async def find_job(self, queue: str, tag: str) -> JobInfo | None:
        """The newest job this print server submitted to `queue` whose name
        contains `tag` — how a restarted print server learns whether it had
        already handed a job to CUPS before it crashed, so it never prints
        one twice."""
        operation = self._base_attributes()
        operation["printer-uri"] = self._printer_uri(queue)
        operation["requesting-user-name"] = ipp.name(self.user)
        operation["which-jobs"] = ipp.keyword("all")
        operation["my-jobs"] = ipp.IppValue(ipp.BOOLEAN, True)
        operation["requested-attributes"] = [ipp.keyword(a) for a in ("job-id",) + _JOB_ATTRIBUTES]
        response = await self._call(self._printer_path(queue), ipp.GET_JOBS, operation)
        if not response.ok:
            if response.status == 0x0406:
                return None
            raise self._failure(response, "Could not list print jobs")
        matches = [j for j in response.group_attributes(ipp.JOB_ATTRIBUTES) if tag in (j.get("job-name") or "")]
        if not matches:
            return None
        newest = max(matches, key=lambda j: j.get("job-id", 0))
        return self._job_info(newest["job-id"], newest)

    async def cancel(self, queue: str, job_id: int) -> None:
        """Cancels a job. A job that is already finished or gone is not an error."""
        operation = self._base_attributes()
        operation["printer-uri"] = self._printer_uri(queue)
        operation["job-id"] = ipp.integer(job_id)
        operation["requesting-user-name"] = ipp.name(self.user)
        response = await self._call(self._printer_path(queue), ipp.CANCEL_JOB, operation)
        # not-found, and not-possible (already completed/canceled) are both "nothing left to cancel".
        if not response.ok and response.status not in (0x0406, 0x0404):
            raise self._failure(response, "Could not cancel the print job")
