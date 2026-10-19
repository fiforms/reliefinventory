"""The R Station print server: claims print jobs from the website and prints
them on this device's CUPS queues.

This is the Pi implementation of rstation/PRINT_CLIENT_SPEC.md (read it for the
rules; section numbers below refer to it) against the wire format in
rstation/PRINT_SERVER.md / rstation/print-extension.openapi.yaml. It runs as a
background task inside the platform's backend (see Product.background_tasks),
beside — not instead of — the platform's own 5-minute heartbeat, whose payload
also carries the printer list (heartbeat_payload below).

Four loops share one set of state:

  queue watch   every few seconds: ask CUPS for each queue's state and, the
                moment any queue's state changes, trigger a heartbeat (s.2)
  heartbeat     report print_printers to the website — on change, and at least
                every 45 s — and read back print_realtime (the wake-up channel)
  job loop      poll for jobs (fast, or slow while the wake-up socket is up),
                one worker per queue; each job: claim -> download -> CUPS ->
                follow it to the end -> report (s.3)
  wake socket   optional, on its own thread (wake.py); only says "poll now"

Everything that can go wrong lands in the ledger (ledger.py) so a restart never
prints a job twice and never loses a result (s.3.7).
"""
import asyncio
import logging
import os
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

from . import ledger as ledger_module
from .cups_client import ERROR, IDLE, OFFLINE, PRINTING, CupsClient, CupsError, PrinterInfo
from .ipp import JOB_CANCELED, JOB_COMPLETED
from .ledger import CLAIMED, FINISHED, REPORTED, SUBMITTED, Ledger
from .print_api import ApiError, Conflict, Gone, NotFound, PrintApi, RateLimited, Revoked, Transient
from .wake import WakeListener

log = logging.getLogger("print_station")

PLATFORM = "rstation"


def _env_float(name: str, default: float) -> float:
    try:
        return float(os.environ[name])
    except (KeyError, ValueError):
        return default


@dataclass
class Settings:
    poll_fast: float = 4.0  # job poll while the wake-up socket is not connected
    poll_slow: float = 45.0  # ...and while it is (the socket does the work)
    queue_watch: float = 8.0  # how often CUPS' queue states are read
    heartbeat: float = 45.0  # at most this long between our own heartbeats
    heartbeat_gap: float = 2.0  # never heartbeat more often than this, however often state flaps
    job_poll: float = 2.0  # how often a running job is checked in CUPS
    bad_grace: float = 15.0  # a printer must look offline/errored this long before a job is given up on
    stall: float = 300.0  # no progress at all for this long: cancel the job
    max_runtime: float = 3600.0  # absolute ceiling for one job
    offline_penalty: float = 120.0  # after a printer-caused failure, report it offline (and hold jobs) this long
    keepalive_min: float = 15.0  # never re-claim more often than this
    download_attempts: int = 3
    report_backoff_max: float = 60.0
    cups_url: str = "http://localhost:631"
    state_dir: Path = ledger_module.DEFAULT_DIR

    @classmethod
    def from_env(cls) -> "Settings":
        """Defaults, overridable with PRINT_STATION_<NAME> variables (seconds)."""
        defaults = cls()
        values = {name: _env_float(f"PRINT_STATION_{name.upper()}", getattr(defaults, name))
                  for name in ("poll_fast", "poll_slow", "queue_watch", "heartbeat", "job_poll", "bad_grace",
                               "stall", "max_runtime", "offline_penalty")}
        return cls(**values,
                   cups_url=os.environ.get("PRINT_STATION_CUPS_URL", defaults.cups_url),
                   state_dir=Path(os.environ.get("PRINT_STATION_STATE_DIR", str(defaults.state_dir))))


class _Claim:
    """One claimed job's keep-alive state: `lost` is set if the website says the
    claim is gone (staff cancelled the job, or it expired and was re-queued)."""

    def __init__(self, job_id: int):
        self.job_id = job_id
        self.lost = asyncio.Event()


async def _default_revoked() -> None:
    # The platform's own response to a 401 (heartbeat.py): wipe the pairing and
    # reboot into setup. Imported late — core modules must not be imported by a
    # product at module level in tests that run without the platform.
    import pairing
    import system_control

    pairing.unpair_and_wipe()
    await system_control.reboot()


def _default_is_paired() -> bool:
    import pairing

    return pairing.is_paired()


def _parse_time(value: str | None) -> datetime | None:
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")) if value else None
    except (ValueError, AttributeError):
        return None


class PrintStation:
    def __init__(self, api: PrintApi, cups: CupsClient, ledger: Ledger, wake: WakeListener | None = None,
                 settings: Settings | None = None, *, is_paired=_default_is_paired, on_revoked=_default_revoked,
                 clock=time.monotonic, sleep=asyncio.sleep, wall=lambda: datetime.now(timezone.utc)):
        self.api = api
        self.cups = cups
        self.ledger = ledger
        self.settings = settings or Settings()
        self.wake = wake or WakeListener(self._wake_from_thread)
        self._is_paired = is_paired
        self._on_revoked = on_revoked
        self._clock = clock
        self._sleep = sleep
        self._wall = wall

        self._states: dict[str, PrinterInfo] = {}  # what CUPS says, per queue
        self._snapshot: list[dict] = []  # what we last decided to report (CUPS + penalties)
        self._reported_nonempty = False  # once we've reported queues, keep reporting (incl. "none left")
        self._penalty: dict[str, tuple[float, str]] = {}  # queue -> (until, why)
        self._busy: set[str] = set()  # queues with a job in hand: one at a time per queue
        self._tasks: set[asyncio.Task] = set()
        self._poll_now = asyncio.Event()
        self._beat_now = asyncio.Event()
        self._loop: asyncio.AbstractEventLoop | None = None
        self._stopped = False
        self._revoked_handled = False
        self.last_poll: datetime | None = None
        self.last_error: str | None = None

    # ------------------------------------------------------------------ reporting

    def _compose_snapshot(self) -> list[dict]:
        now = self._clock()
        snapshot = []
        for info in sorted(self._states.values(), key=lambda p: p.name):
            state, message = info.state, info.message
            penalty = self._penalty.get(info.name)
            if penalty and penalty[0] > now and state in (IDLE, PRINTING):
                state, message = OFFLINE, penalty[1]
            snapshot.append({"queue_name": info.name, "state": state, "state_message": message})
        return snapshot

    def _publish_snapshot(self) -> bool:
        snapshot = self._compose_snapshot()
        if snapshot == self._snapshot:
            return False
        self._snapshot = snapshot
        self._beat_now.set()
        return True

    async def refresh_queues(self) -> bool:
        """Re-reads every queue's state from CUPS. Returns True if what we'd
        report changed (which also triggers a heartbeat)."""
        try:
            found = await self.cups.printers()
            self._states = {p.name: p for p in found}
        except CupsError as exc:
            # CUPS itself is down or unreachable: every queue we know is unusable until it's back.
            self._states = {name: PrinterInfo(name, OFFLINE, f"Print system (CUPS) is not responding: {exc}")
                            for name in self._states}
        return self._publish_snapshot()

    def heartbeat_payload(self) -> dict:
        """The print keys for a heartbeat — used for our own and merged into the
        platform's (Product.heartbeat_payload). Empty until this device has
        queues, so a plain kiosk with no printers adds nothing."""
        if not self._snapshot and not self._reported_nonempty:
            return {}
        if self._snapshot:
            self._reported_nonempty = True
        return {"print_platform": PLATFORM, "print_printers": list(self._snapshot)}

    def on_heartbeat_response(self, response: dict) -> None:
        """Called by the platform with each of *its* heartbeat responses (every 5
        minutes) — a second chance to learn the wake-up channel."""
        self.wake.configure(response.get("print_realtime"))

    async def send_heartbeat(self) -> None:
        payload = self.heartbeat_payload()
        if not payload:
            return
        response = await self.api.heartbeat(payload)
        self.wake.configure(response.get("print_realtime"))

    def status(self) -> dict:
        return {"print_station": {
            "queues": list(self._snapshot),
            "realtime_connected": self.wake.connected,
            "busy": sorted(self._busy),
            "last_poll": self.last_poll.isoformat() if self.last_poll else None,
            "last_error": self.last_error,
        }}

    # ----------------------------------------------------------------------- jobs

    def _printable(self, queue: str) -> bool:
        """A job is only claimed when its printer can take it right now: a job sent
        to an offline printer must wait in the website's queue ("will print when
        back"), not be claimed and failed."""
        entry = next((q for q in self._snapshot if q["queue_name"] == queue), None)
        return entry is not None and entry["state"] in (IDLE, PRINTING)

    async def poll_jobs(self) -> None:
        jobs = await self.api.jobs()
        self.last_poll = self._wall()
        self.last_error = None
        if not jobs:
            return
        await self.refresh_queues()
        for job in jobs:
            queue = job["queue_name"]
            if queue in self._busy or not self._printable(queue):
                continue
            self._busy.add(queue)
            self._spawn(self._handle(job))

    def _spawn(self, coroutine) -> None:
        task = asyncio.ensure_future(coroutine)
        self._tasks.add(task)
        task.add_done_callback(self._tasks.discard)

    async def drain(self) -> None:
        """Waits for every job in flight (tests, and a tidy shutdown)."""
        while self._tasks:
            await asyncio.gather(*list(self._tasks), return_exceptions=True)

    async def _handle(self, job: dict) -> None:
        try:
            await self._process(job)
        except Revoked:
            await self._revoked()
        except Exception:  # noqa: BLE001 - one bad job must never stop the others
            log.exception("Unexpected error handling print job %s", job.get("id"))
        finally:
            self._busy.discard(job["queue_name"])

    async def _process(self, job: dict) -> None:
        job_id, queue = job["id"], job["queue_name"]

        known = self.ledger.get(job_id)
        if known:
            # We have already worked on this job — the website is offering it again (our claim
            # lapsed, say). Never print it a second time: just settle what we know.
            if known.get("result"):
                await self._resettle(job_id, known)
            else:
                await self._recover_entry(job_id, known)
            return

        try:
            claim_response = await self.api.claim(job_id)
        except (Conflict, NotFound):
            return  # someone else has it, or it was cancelled: skip
        claim = _Claim(job_id)
        self.ledger.put(job_id, state=CLAIMED, queue=queue, title=job.get("title"))
        keepalive = asyncio.ensure_future(self._keepalive(claim, claim_response))
        try:
            try:
                pdf = await self._download(job_id)
            except Gone:
                await self._finish(job_id, "failed", "The file for this job is no longer available on the server. Print the document again.")
                return
            except (Conflict, NotFound):
                self.ledger.forget(job_id)  # claim lost before we printed anything
                return
            except (Transient, RateLimited, ApiError) as exc:
                # Couldn't fetch the PDF: print nothing, let the claim lapse, and the website re-offers the job.
                log.warning("Giving up on downloading job %s for now: %s", job_id, exc)
                self.ledger.forget(job_id)
                return
            if claim.lost.is_set():
                self.ledger.forget(job_id)
                return

            # The tag is how recovery finds this job in CUPS again; keep it intact when shortening the title.
            title = f"{(job.get('title') or 'Print job')[:200]} {self._tag(job_id)}"
            try:
                cups_id = await self.cups.submit(queue, pdf, title, job.get("copies") or 1)
            except CupsError as exc:
                self._penalize(queue, str(exc))
                await self._finish(job_id, "failed", str(exc))
                return
            self.ledger.put(job_id, state=SUBMITTED, cups_job_id=cups_id)

            outcome = await self._await_job(queue, cups_id, claim)
            if outcome is None:
                self.ledger.forget(job_id)  # claim lost mid-print; we cancelled the CUPS job. If the website re-offers it, print afresh
                return
            result, error = outcome
            if result == "printed":
                self._penalty.pop(queue, None)
            await self._finish(job_id, result, error)
        finally:
            keepalive.cancel()

    async def _resettle(self, job_id: int, known: dict) -> None:
        """The website offers a job we already have an outcome for (our earlier report lost a
        race — e.g. the claim lapsed just as the job finished). Claim it again purely to tell
        the website what happened; nothing is printed."""
        try:
            await self.api.claim(job_id)
        except (Conflict, NotFound):
            return
        self.ledger.put(job_id, state=FINISHED)
        await self._report(job_id)

    @staticmethod
    def _tag(job_id: int) -> str:
        return f"[ri-{job_id}]"

    async def _download(self, job_id: int) -> bytes:
        delay = 1.0
        for attempt in range(1, self.settings.download_attempts + 1):
            try:
                return await self.api.payload(job_id)
            except (Transient, RateLimited):
                if attempt == self.settings.download_attempts:
                    raise
                await self._sleep(delay)
                delay *= 2
        raise Transient("unreachable")  # pragma: no cover

    async def _keepalive(self, claim: _Claim, response: dict) -> None:
        """Re-claims the job at half its remaining claim time (spec s.3.2) so a long
        print isn't reclaimed and printed again. Stops when the website says the
        claim is gone."""
        while True:
            expires = _parse_time(response.get("claim_expires_at"))
            remaining = (expires - self._wall()).total_seconds() if expires else 120.0
            await self._sleep(max(self.settings.keepalive_min, remaining / 2))
            try:
                response = await self.api.claim(claim.job_id)
            except (Conflict, NotFound, Revoked):
                claim.lost.set()
                return
            except (Transient, RateLimited, ApiError):
                response = {}  # try again at the default cadence

    async def _await_job(self, queue: str, cups_id: int, claim: _Claim) -> tuple[str, str | None] | None:
        """Follows a CUPS job to its end -> ("printed", None) | ("failed", why), or
        None if the claim was lost and the job was cancelled."""
        started = last_progress = self._clock()
        bad_since: float | None = None
        last_signature = None
        while True:
            if claim.lost.is_set():
                await self._cancel_quietly(queue, cups_id)
                return None
            try:
                job, cups_reachable = await self.cups.job(queue, cups_id), True
            except CupsError:
                job, cups_reachable = None, False  # CUPS unreachable for a moment: keep waiting; the stall timer still applies
            if cups_reachable and job is None:
                return "failed", "The print system lost track of this job. Check the printer, then print again if it didn't come out."
            if job:
                if job.state == JOB_COMPLETED:
                    return "printed", None
                if job.finished:
                    why = "cancelled" if job.state == JOB_CANCELED else "aborted by the print system"
                    detail = f" ({', '.join(job.reasons)})" if job.reasons else ""
                    return "failed", f"The print job was {why}{detail}."

            await self.refresh_queues()
            printer = self._states.get(queue)
            now = self._clock()
            signature = (job and (job.state, tuple(job.reasons), job.impressions, job.sheets), printer and (printer.state, printer.message))
            if signature != last_signature:
                last_signature, last_progress = signature, now

            if printer and printer.state in (OFFLINE, ERROR):
                bad_since = bad_since if bad_since is not None else now
                if now - bad_since >= self.settings.bad_grace:
                    why = printer.message or "The printer is not ready."
                    await self._cancel_quietly(queue, cups_id)
                    self._penalize(queue, why)
                    return "failed", why
            else:
                bad_since = None

            if now - last_progress >= self.settings.stall:
                await self._cancel_quietly(queue, cups_id)
                why = f"The printer made no progress for {int(self.settings.stall // 60) or 1} minutes."
                if printer and printer.message:
                    why += f" ({printer.message})"
                self._penalize(queue, why)
                return "failed", why
            if now - started >= self.settings.max_runtime:
                await self._cancel_quietly(queue, cups_id)
                return "failed", f"The job was still not finished after {int(self.settings.max_runtime // 60)} minutes and was cancelled."
            await self._sleep(self.settings.job_poll)

    async def _cancel_quietly(self, queue: str, cups_id: int) -> None:
        try:
            await self.cups.cancel(queue, cups_id)
        except CupsError as exc:
            log.warning("Could not cancel CUPS job %s on %s: %s", cups_id, queue, exc)

    def _penalize(self, queue: str, why: str) -> None:
        """After a printer-caused failure, report the queue offline (and claim no
        more jobs for it) for a couple of minutes, instead of failing the next
        job the same way and hammering a dead printer."""
        self._penalty[queue] = (self._clock() + self.settings.offline_penalty, why)
        self._publish_snapshot()

    async def _finish(self, job_id: int, result: str, error: str | None) -> None:
        self.ledger.put(job_id, state=FINISHED, result=result, error=error)
        await self._report(job_id)

    async def _report(self, job_id: int) -> None:
        """Tells the website the outcome — retrying through outages, because a result
        must not be lost — and records that it did."""
        entry = self.ledger.get(job_id) or {}
        delay = 2.0
        while not self._stopped:
            try:
                await self.api.result(job_id, entry["result"], entry.get("error"))
                break
            except (Conflict, NotFound):
                break  # settled elsewhere (claim expired, job cancelled): nothing more to say
            except Revoked:
                raise
            except (Transient, RateLimited):
                await self._sleep(delay)
                delay = min(delay * 2, self.settings.report_backoff_max)
            except ApiError as exc:
                log.warning("The website rejected the result for job %s: %s", job_id, exc)
                break
        self.ledger.put(job_id, state=REPORTED)

    # ------------------------------------------------------------------- recovery

    async def recover(self) -> None:
        """At start-up: settle every job a previous run left unfinished."""
        for job_id, entry in self.ledger.unsettled():
            try:
                await self._recover_entry(job_id, entry)
            except Revoked:
                raise
            except Exception:  # noqa: BLE001
                log.exception("Could not recover print job %s", job_id)
        self.ledger.prune()

    async def _recover_entry(self, job_id: int, entry: dict) -> None:
        state, queue = entry.get("state"), entry.get("queue")
        if state == FINISHED:
            await self._report(job_id)
            return
        if state not in (CLAIMED, SUBMITTED):
            return  # reported: the website already knows
        try:
            if entry.get("cups_job_id"):
                job = await self.cups.job(queue, entry["cups_job_id"])
            else:  # crashed before we recorded CUPS' id: did the job reach CUPS at all?
                job = await self.cups.find_job(queue, self._tag(job_id))
        except CupsError as exc:
            log.warning("Cannot check job %s in CUPS yet: %s", job_id, exc)
            return  # try again next time the website offers it, or next start
        if job is None:
            await self._finish(job_id, "failed", "The print server restarted and couldn't confirm whether this printed. "
                                                 "Check the printer, then print again if it didn't come out.")
        elif job.completed:
            await self._finish(job_id, "printed", None)
        elif job.finished:
            await self._finish(job_id, "failed", "The print job was cancelled or aborted while the print server was restarting.")
        else:
            # Still printing: keep following it (there is no claim of ours left to keep alive).
            self._busy.add(queue)
            self._spawn(self._resume(job_id, queue, job.job_id))

    async def _resume(self, job_id: int, queue: str, cups_id: int) -> None:
        try:
            outcome = await self._await_job(queue, cups_id, _Claim(job_id))
            if outcome:
                await self._finish(job_id, *outcome)
        except Revoked:
            await self._revoked()
        except Exception:  # noqa: BLE001
            log.exception("Unexpected error following print job %s", job_id)
        finally:
            self._busy.discard(queue)

    # ---------------------------------------------------------------------- loops

    def _wake_from_thread(self) -> None:
        """The wake-up socket's thread says a job is waiting."""
        if self._loop:
            self._loop.call_soon_threadsafe(self._poll_now.set)

    async def _wait(self, event: asyncio.Event, timeout: float) -> None:
        """Sleeps up to `timeout`, or until `event` is set — through the injected
        sleep, so tests can run the loops in virtual time."""
        waiter, timer = asyncio.ensure_future(event.wait()), asyncio.ensure_future(self._sleep(timeout))
        try:
            await asyncio.wait({waiter, timer}, return_when=asyncio.FIRST_COMPLETED)
        finally:
            waiter.cancel()
            timer.cancel()
        event.clear()

    async def _revoked(self) -> None:
        if self._revoked_handled:
            return
        self._revoked_handled = True
        self._stopped = True
        self.wake.stop()
        log.warning("The website revoked this device; returning to setup.")
        await self._on_revoked()

    async def _queue_loop(self) -> None:
        while not self._stopped:
            try:
                await self.refresh_queues()
            except Exception:  # noqa: BLE001
                log.exception("Reading print queues failed")
            await self._sleep(self.settings.queue_watch)

    async def _heartbeat_loop(self) -> None:
        while not self._stopped:
            # Whatever changed before this beat is about to be in it; only a change from here on needs another.
            self._beat_now.clear()
            try:
                await self.send_heartbeat()
            except Revoked:
                await self._revoked()
                return
            except (Transient, RateLimited, ApiError) as exc:
                self.last_error = str(exc)
            except Exception:  # noqa: BLE001
                log.exception("Print heartbeat failed")
            await self._sleep(self.settings.heartbeat_gap)
            await self._wait(self._beat_now, self.settings.heartbeat)

    async def _job_loop(self) -> None:
        while not self._stopped:
            extra = 0.0
            try:
                await self.poll_jobs()
            except Revoked:
                await self._revoked()
                return
            except RateLimited as exc:
                self.last_error, extra = str(exc), 15.0
            except (Transient, ApiError) as exc:
                self.last_error = str(exc)
            except Exception:  # noqa: BLE001
                log.exception("Polling for print jobs failed")
            await self._sleep(extra)
            await self._wait(self._poll_now, self.settings.poll_slow if self.wake.connected else self.settings.poll_fast)

    async def run(self) -> None:
        """The product's background task: never raises (a bug here must not take the
        backend down), idles while unpaired, and stops everything if cancelled."""
        self._loop = asyncio.get_running_loop()
        try:
            while True:
                try:
                    if not self._is_paired():
                        await self._sleep(10)
                        continue
                    self._stopped = self._revoked_handled = False
                    await self.recover()
                    await asyncio.gather(self._queue_loop(), self._heartbeat_loop(), self._job_loop())
                except asyncio.CancelledError:
                    raise
                except Revoked:
                    await self._revoked()
                    await self._sleep(10)
                except Exception:  # noqa: BLE001
                    log.exception("Print station crashed; restarting in 10 s")
                    await self._sleep(10)
        finally:
            self._stopped = True
            self.wake.stop()


def default_station(settings: Settings | None = None) -> PrintStation:
    settings = settings or Settings.from_env()
    return PrintStation(api=PrintApi(), cups=CupsClient(settings.cups_url), ledger=Ledger(settings.state_dir), settings=settings)
