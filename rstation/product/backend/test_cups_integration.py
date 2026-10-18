"""Opt-in tests against a REAL CUPS scheduler — the ones that proved the IPP client and
the offline/error classification on real hardware behaviour (see rstation/PRINT_CLIENT_SPEC.md
section 2). Skipped unless RI_CUPS_INTEGRATION=1 and `lpadmin` is available:

    RI_CUPS_INTEGRATION=1 PRODUCT_ROOT=$PWD/rstation/product PRODUCT=portal \\
        rstation/platform/local-app/run-tests.sh -q -k cups_integration

It needs permission to add queues (membership of `lpadmin`, or root). It only ever creates
throwaway queues named ri-it-<pid>-* whose device is a socket on 127.0.0.1 (or an unresolvable
host) — never a real printer — and removes them afterwards. A raw queue is used, which newer
CUPS deprecates; if `lpadmin -m raw` stops working, switch to a different loopback model.
"""
import asyncio
import os
import shutil
import socket
import socketserver
import subprocess
import threading
from datetime import datetime, timezone

import pytest

from . import ipp
from .cups_client import ERROR, IDLE, OFFLINE, CupsClient
from .fakes import FakeApi
from .ledger import Ledger
from .print_station import PrintStation, Settings

pytestmark = pytest.mark.skipif(
    os.environ.get("RI_CUPS_INTEGRATION") != "1" or not shutil.which("lpadmin"),
    reason="set RI_CUPS_INTEGRATION=1 (and have CUPS' lpadmin) to run against a real CUPS")

PDF = b"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
run = asyncio.run


class _Printer(socketserver.ThreadingTCPServer):
    """A loopback 'printer' that records every byte sent to it."""
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        self.received = bytearray()

        class Handler(socketserver.BaseRequestHandler):
            def handle(handler):  # noqa: N805
                while chunk := handler.request.recv(65536):
                    self.received.extend(chunk)

        super().__init__(("127.0.0.1", 0), Handler)


def _free_closed_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def _lp(*args):
    subprocess.run(args, check=True, capture_output=True)


@pytest.fixture(scope="module")
def env():
    printer = _Printer()
    threading.Thread(target=printer.serve_forever, daemon=True).start()
    stem = f"ri-it-{os.getpid()}"
    queues = {
        "ok": (f"{stem}-ok", f"socket://127.0.0.1:{printer.server_address[1]}"),
        "down": (f"{stem}-down", f"socket://127.0.0.1:{_free_closed_port()}"),
        "nohost": (f"{stem}-nohost", "socket://no-such-host.invalid:9100"),
    }
    created = []
    try:
        for name, uri in queues.values():
            _lp("lpadmin", "-p", name, "-E", "-v", uri, "-m", "raw")
            created.append(name)
        yield {"printer": printer, **{key: name for key, (name, _) in queues.items()}}
    finally:
        for name in created:
            subprocess.run(["lpadmin", "-x", name], capture_output=True)
        printer.shutdown()


async def _astates(client, *names):
    return {p.name: p for p in await client.printers() if p.name in names}


def _states(client, *names):
    return run(_astates(client, *names))


def test_our_queues_are_listed_and_idle(env):
    states = _states(CupsClient(), env["ok"], env["down"], env["nohost"])

    assert {p.state for p in states.values()} == {IDLE}  # nothing is being attempted yet


def test_a_pdf_is_delivered_with_the_right_number_of_copies(env):
    client = CupsClient()
    before = bytes(env["printer"].received).count(b"%PDF")

    async def scenario():
        job_id = await client.submit(env["ok"], PDF, "integration [ri-it-1]", copies=2)
        for _ in range(40):
            job = await client.job(env["ok"], job_id)
            if job.finished:
                return job
            await asyncio.sleep(0.25)

    job = run(scenario())

    assert job.completed and "job-completed-successfully" in job.reasons
    assert bytes(env["printer"].received).count(b"%PDF") - before == 2


def test_a_paused_queue_reads_offline_and_recovers(env):
    client = CupsClient()
    _lp("cupsdisable", env["ok"])
    try:
        assert _states(client, env["ok"])[env["ok"]].state == OFFLINE
        assert _states(client, env["ok"])[env["ok"]].message == "Printer paused"
    finally:
        _lp("cupsenable", env["ok"])
    assert _states(client, env["ok"])[env["ok"]].state == IDLE


@pytest.mark.parametrize("key, phrase", [("down", "may not exist or is unavailable"), ("nohost", "Unable to locate printer")])
def test_an_unreachable_printer_reads_offline_with_cups_own_message_and_cancels_cleanly(env, key, phrase):
    client, queue = CupsClient(), env[key]

    async def scenario():
        job_id = await client.submit(queue, PDF, f"unreachable [ri-it-{key}]")
        seen = None
        for _ in range(40):
            seen = (await _astates(client, queue))[queue]
            if seen.state == OFFLINE:
                break
            await asyncio.sleep(0.25)
        await client.cancel(queue, job_id)
        return seen, await client.job(queue, job_id)

    seen, job = run(scenario())

    assert seen.state == OFFLINE and phrase in seen.message
    assert job.state == ipp.JOB_CANCELED
    assert _states(client, queue)[queue].state in (IDLE, ERROR, OFFLINE)  # and the queue is usable again after cancel


def test_find_job_locates_a_submitted_job_by_its_tag(env):
    client = CupsClient()

    async def scenario():
        job_id = await client.submit(env["ok"], PDF, "Pallet label [ri-it-find]")
        return job_id, await client.find_job(env["ok"], "[ri-it-find]")

    job_id, found = run(scenario())

    assert found.job_id == job_id


def _station(tmp_path, cups, api):
    start = datetime(2026, 10, 18, 12, 0, tzinfo=timezone.utc)
    return PrintStation(api, cups, Ledger(tmp_path), wake=_NoWake(), is_paired=lambda: True, on_revoked=_nothing,
                        settings=Settings(job_poll=0.3, bad_grace=1.5, stall=30, offline_penalty=60), wall=lambda: start)


class _NoWake:
    connected = False

    def configure(self, realtime):
        pass

    def stop(self):
        pass


async def _nothing():
    pass


def test_the_whole_station_prints_a_job_on_real_cups_and_reports_it(env, tmp_path):
    api = FakeApi()
    api.offered = [{"id": 501, "queue_name": env["ok"], "title": "Pallet label #5", "copies": 1}]
    station = _station(tmp_path, CupsClient(), api)
    before = bytes(env["printer"].received).count(b"%PDF")

    async def scenario():
        await station.refresh_queues()
        await station.poll_jobs()
        await station.drain()

    run(scenario())

    assert api.results == [(501, "printed", None)]
    assert bytes(env["printer"].received).count(b"%PDF") - before == 1


def test_the_whole_station_fails_a_job_for_an_unreachable_printer_with_a_useful_message(env, tmp_path):
    api = FakeApi()
    api.offered = [{"id": 502, "queue_name": env["down"], "title": "Pallet label #6", "copies": 1}]
    cups = CupsClient()
    station = _station(tmp_path, cups, api)

    async def scenario():
        await station.refresh_queues()
        await station.poll_jobs()
        await station.drain()

    run(scenario())

    (job_id, status, error), = api.results
    assert (job_id, status) == (502, "failed")
    assert "may not exist or is unavailable" in error
    assert next(q for q in station._snapshot if q["queue_name"] == env["down"])["state"] == "offline"  # held offline for a while
    assert run(cups.find_job(env["down"], "[ri-502]")).state == ipp.JOB_CANCELED  # and nothing is left to print later
