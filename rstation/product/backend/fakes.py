"""Test doubles for the print server: a scripted fake website API, a scripted
fake CUPS, and a virtual clock, so the whole claim -> print -> report flow can
be run deterministically, in milliseconds, covering minutes of "printer time".
Not a test module itself (no test_ prefix); imported by the test_print_*.py files.
"""
import asyncio
import heapq
import itertools
import struct
from datetime import datetime, timedelta, timezone

import httpx

from . import ipp
from .cups_client import CupsError, JobInfo, PrinterInfo
from .ipp import JOB_ABORTED, JOB_CANCELED, JOB_COMPLETED, JOB_PROCESSING
from .print_api import ApiError


class VirtualTime:
    """A clock that only moves when everything is waiting: `sleep` parks the
    caller until virtual time reaches its deadline, and `drive` advances time
    to the next deadline whenever no task can make progress."""

    def __init__(self):
        self.now = 0.0
        self._waiters: list = []
        self._ids = itertools.count()

    def clock(self) -> float:
        return self.now

    async def sleep(self, delay: float) -> None:
        if delay <= 0:
            await asyncio.sleep(0)
            return
        future = asyncio.get_running_loop().create_future()
        heapq.heappush(self._waiters, (self.now + delay, next(self._ids), future))
        await future

    async def drive(self, awaitable, max_steps: int = 100_000):
        """Runs `awaitable` to completion, advancing virtual time as needed."""
        task = asyncio.ensure_future(awaitable)
        for _ in range(max_steps):
            for _ in range(20):  # let every runnable task run until all are parked
                await asyncio.sleep(0)
                if task.done():
                    return task.result()
            while self._waiters and self._waiters[0][2].cancelled():
                heapq.heappop(self._waiters)
            if not self._waiters:
                continue
            deadline, _, future = heapq.heappop(self._waiters)
            self.now = max(self.now, deadline)
            if not future.done():
                future.set_result(None)
        task.cancel()
        raise AssertionError("virtual time ran out of steps (a loop that never settles?)")


def ipp_response(status: int, groups: list, request_id: int = 1) -> bytes:
    """Builds an IPP response from [(group tag, {name: value | [values]})]. Ints are
    encoded as integers, bools as booleans, strings as names."""
    out = bytearray(struct.pack(">BBHI", 2, 0, status, request_id))
    for group_tag, attributes in groups:
        out.append(group_tag)
        for name, values in attributes.items():
            for index, value in enumerate(values if isinstance(values, list) else [values]):
                if isinstance(value, bool):
                    tag, raw = ipp.BOOLEAN, b"\x01" if value else b"\x00"
                elif isinstance(value, int):
                    tag, raw = ipp.INTEGER, struct.pack(">i", value)
                else:
                    tag, raw = ipp.NAME, str(value).encode()
                encoded_name = name.encode() if index == 0 else b""
                out += struct.pack(">BH", tag, len(encoded_name)) + encoded_name + struct.pack(">H", len(raw)) + raw
    out.append(ipp.END_OF_ATTRIBUTES)
    return bytes(out)


class FakeIppServer(httpx.AsyncBaseTransport):
    """CUPS as seen over HTTP: records each decoded request and answers from
    `responses[operation id]` (bytes, or a callable taking the decoded request)."""

    def __init__(self):
        self.requests: list[dict] = []
        self.responses: dict[int, object] = {}
        self.http_status = 200
        self.connect_error: Exception | None = None

    async def handle_async_request(self, request):
        if self.connect_error:
            raise self.connect_error
        body = request.content
        operation = struct.unpack(">H", body[2:4])[0]
        decoded = ipp.decode_response(body)  # a request has the same layout as a response
        record = {"operation": operation, "path": request.url.raw_path.decode(), "body": body,
                  "attributes": decoded.first(ipp.OPERATION_ATTRIBUTES), "job": decoded.first(ipp.JOB_ATTRIBUTES)}
        self.requests.append(record)
        answer = self.responses.get(operation)
        content = answer(record) if callable(answer) else answer
        return httpx.Response(self.http_status, content=content or b"")


class FakeApi:
    """The website's print API, recording calls; `offered` is the job list it serves. Script outcomes by setting
    `claim_errors[job_id]`, `payload_errors`, `result_errors` (a list: popped one
    per call; an Exception instance is raised, None means succeed)."""

    def __init__(self, wall=None):
        self.wall = wall or (lambda: datetime(2026, 10, 18, 12, 0, tzinfo=timezone.utc))
        self.claim_window = timedelta(minutes=5)
        self.offered: list[dict] = []  # what GET /print-jobs lists
        self.claims: list[int] = []
        self.claim_times: list[tuple] = []  # (job id, wall time) of every claim/keep-alive
        self.results: list[tuple] = []
        self.heartbeats: list[dict] = []
        self.claim_errors: dict[int, list] = {}
        self.payload_errors: dict[int, list] = {}
        self.result_errors: dict[int, list] = {}
        self.heartbeat_response: dict = {"ok": True}
        self.jobs_error: Exception | None = None

    @staticmethod
    def _pop(table: dict, job_id: int):
        errors = table.get(job_id) or []
        if errors:
            error = errors.pop(0)
            if isinstance(error, Exception):
                raise error

    async def heartbeat(self, payload):
        self.heartbeats.append(payload)
        return self.heartbeat_response

    async def jobs(self):
        if self.jobs_error:
            raise self.jobs_error
        return list(self.offered)

    async def claim(self, job_id):
        self.claims.append(job_id)
        self.claim_times.append((job_id, self.wall()))
        self._pop(self.claim_errors, job_id)
        return {"id": job_id, "claim_expires_at": (self.wall() + self.claim_window).isoformat()}

    async def payload(self, job_id):
        self._pop(self.payload_errors, job_id)
        return b"%PDF-1.4 fake pdf for job " + str(job_id).encode()

    async def result(self, job_id, status, error=None):
        self._pop(self.result_errors, job_id)
        self.results.append((job_id, status, error))
        # Like the real server: a job with a recorded result is no longer on offer.
        self.offered = [job for job in self.offered if job["id"] != job_id]
        return {"id": job_id, "status": status}


class FakeCups:
    """A CUPS whose queues and jobs are scripted.

    `queues`: name -> PrinterInfo. A submitted job follows `behaviour(queue)`
    (a callable returning a list of JobInfo-state tuples, consumed one per
    poll; the last repeats). By default a job completes on its first poll.
    """

    def __init__(self, vt: VirtualTime | None = None):
        self.vt = vt
        self.queues: dict[str, PrinterInfo] = {}
        self.submitted: list[dict] = []
        self.cancelled: list[tuple] = []
        self.jobs: dict[int, dict] = {}
        self.script: dict[str, list] = {}  # queue -> list of (state, impressions) per poll
        self.submit_error: CupsError | None = None
        self.unreachable = False
        self.on_poll = None  # callable(queue, polls) run on every job() call, to change the world mid-job
        self._ids = itertools.count(100)

    def add_queue(self, name: str, state: str = "idle", message: str | None = None) -> None:
        self.queues[name] = PrinterInfo(name, state, message)

    async def printers(self):
        if self.unreachable:
            raise CupsError("connection refused")
        return sorted(self.queues.values(), key=lambda p: p.name)

    async def submit(self, queue, pdf, title, copies=1, job_options=None):
        if self.submit_error:
            raise self.submit_error
        job_id = next(self._ids)
        self.submitted.append({"queue": queue, "pdf": pdf, "title": title, "copies": copies, "job_id": job_id})
        self.jobs[job_id] = {"queue": queue, "title": title, "polls": 0, "state": JOB_PROCESSING}
        return job_id

    def _state_for(self, queue, polls):
        steps = self.script.get(queue)
        if not steps:
            return JOB_COMPLETED, None
        return steps[min(polls, len(steps) - 1)]

    async def job(self, queue, job_id):
        record = self.jobs.get(job_id)
        if record is not None:
            record["polls"] += 1
            if self.on_poll:  # runs even while "unreachable", so a test can end the outage
                self.on_poll(queue, record["polls"])
        if self.unreachable:
            raise CupsError("connection refused")
        if record is None:
            return None
        if record.get("cancelled"):
            return JobInfo(job_id, JOB_CANCELED, ["job-canceled-by-user"])
        state, impressions = self._state_for(queue, record["polls"] - 1)
        reasons = {JOB_COMPLETED: ["job-completed-successfully"], JOB_ABORTED: ["aborted-by-system"]}.get(state, ["job-printing"])
        return JobInfo(job_id, state, reasons, name=record["title"], impressions=impressions)

    async def find_job(self, queue, tag):
        found = [(i, r) for i, r in self.jobs.items() if r["queue"] == queue and tag in r["title"]]
        if not found:
            return None
        job_id, record = max(found)
        return await self.job(queue, job_id)

    async def cancel(self, queue, job_id):
        self.cancelled.append((queue, job_id))
        if job_id in self.jobs:
            self.jobs[job_id]["cancelled"] = True
