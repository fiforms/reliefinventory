"""The print server's behaviour, run end to end against fakes in virtual time (fakes.py) —
every rule of rstation/PRINT_CLIENT_SPEC.md section 3 that a client must keep:
printable-only claiming, keep-alive, failure messages, never printing twice, never
losing a result. test_cups_integration.py repeats the key paths on a real CUPS."""
import asyncio
import threading
from datetime import datetime, timedelta, timezone

import pytest

from .cups_client import CupsError
from .fakes import FakeApi, FakeCups, VirtualTime
from .ipp import JOB_ABORTED, JOB_CANCELED, JOB_COMPLETED, JOB_PROCESSING
from .ledger import CLAIMED, FINISHED, REPORTED, SUBMITTED, Ledger
from .print_api import Conflict, Gone, NotFound, RateLimited, Revoked, Transient
from .print_station import PrintStation, Settings

START = datetime(2026, 10, 18, 12, 0, tzinfo=timezone.utc)
PROCESSING = (JOB_PROCESSING, 0)


class FakeWake:
    def __init__(self):
        self.connected = False
        self.configured: list = []
        self.stopped = 0

    def configure(self, realtime):
        self.configured.append(realtime)

    def stop(self):
        self.stopped += 1


class Harness:
    def __init__(self, tmp_path, **settings):
        self.vt = VirtualTime()
        self.wall = lambda: START + timedelta(seconds=self.vt.now)
        self.api = FakeApi(self.wall)
        self.cups = FakeCups(self.vt)
        self.wake = FakeWake()
        self.ledger = Ledger(tmp_path, clock=self.vt.clock)
        self.paired = True
        self.revoked = 0

        async def on_revoked():
            self.revoked += 1

        self.station = PrintStation(
            self.api, self.cups, self.ledger, wake=self.wake, settings=Settings(**settings),
            is_paired=lambda: self.paired, on_revoked=on_revoked,
            clock=self.vt.clock, sleep=self.vt.sleep, wall=self.wall)

    def run(self, coroutine):
        return asyncio.run(self.vt.drive(coroutine))

    def offer(self, job_id, queue="Zebra", title="Pallet label #1", copies=1):
        self.api.offered.append({"id": job_id, "queue_name": queue, "title": title, "copies": copies})

    async def work(self):
        """One poll cycle, and wait for the jobs it started."""
        await self.station.refresh_queues()
        await self.station.poll_jobs()
        await self.station.drain()

    def process(self):
        return self.run(self.work())

    def result(self, job_id):
        return [r for r in self.api.results if r[0] == job_id]


@pytest.fixture
def h(tmp_path):
    harness = Harness(tmp_path)
    harness.cups.add_queue("Zebra")
    return harness


# --------------------------------------------------------------------- reporting printers

def test_no_print_keys_on_the_heartbeat_until_a_queue_exists(tmp_path):
    h = Harness(tmp_path)
    h.run(h.station.refresh_queues())

    assert h.station.heartbeat_payload() == {}


def test_queues_are_reported_with_state_and_message_and_reporting_continues_if_they_vanish(h):
    h.cups.add_queue("Laser", "error", "Out of paper or labels")
    h.run(h.station.refresh_queues())

    assert h.station.heartbeat_payload() == {"print_platform": "rstation", "print_printers": [
        {"queue_name": "Laser", "state": "error", "state_message": "Out of paper or labels"},
        {"queue_name": "Zebra", "state": "idle", "state_message": None}]}

    h.cups.queues.clear()  # every queue removed: tell the website, don't go silent (spec s.2)
    h.run(h.station.refresh_queues())
    assert h.station.heartbeat_payload() == {"print_platform": "rstation", "print_printers": []}


def test_a_state_change_triggers_a_heartbeat_but_an_identical_read_does_not(h):
    assert h.run(h.station.refresh_queues()) is True
    h.station._beat_now.clear()

    assert h.run(h.station.refresh_queues()) is False
    assert not h.station._beat_now.is_set()

    h.cups.queues["Zebra"].state = "error"
    assert h.run(h.station.refresh_queues()) is True
    assert h.station._beat_now.is_set()


def test_cups_going_down_marks_every_known_queue_offline(h):
    h.run(h.station.refresh_queues())
    h.cups.unreachable = True

    h.run(h.station.refresh_queues())

    (queue,) = h.station.heartbeat_payload()["print_printers"]
    assert queue["state"] == "offline" and "CUPS" in queue["state_message"]


def test_send_heartbeat_posts_the_payload_and_learns_the_wake_channel(h):
    h.api.heartbeat_response = {"ok": True, "print_realtime": {"channel": "wake.abc", "host": "h"}}
    h.run(h.station.refresh_queues())

    h.run(h.station.send_heartbeat())

    assert h.api.heartbeats[0]["print_platform"] == "rstation"
    assert h.wake.configured == [{"channel": "wake.abc", "host": "h"}]


def test_no_heartbeat_is_sent_when_there_is_nothing_to_report(tmp_path):
    h = Harness(tmp_path)

    h.run(h.station.send_heartbeat())

    assert h.api.heartbeats == []


def test_the_platforms_own_heartbeat_response_also_updates_the_wake_channel(h):
    h.station.on_heartbeat_response({"print_realtime": {"channel": "wake.xyz"}})
    h.station.on_heartbeat_response({"ok": True})  # realtime turned off

    assert h.wake.configured == [{"channel": "wake.xyz"}, None]


# ------------------------------------------------------------------------- the happy path

def test_a_job_is_claimed_downloaded_printed_and_reported(h):
    h.offer(1, title="Pallet label #1", copies=3)

    h.process()

    assert h.api.claims == [1]
    (submitted,) = h.cups.submitted
    assert submitted["queue"] == "Zebra" and submitted["copies"] == 3
    assert submitted["pdf"] == b"%PDF-1.4 fake pdf for job 1"
    assert submitted["title"] == "Pallet label #1 [ri-1]"  # the tag is how recovery finds the job later
    assert h.api.results == [(1, "printed", None)]
    assert h.ledger.get(1)["state"] == REPORTED


def test_a_very_long_title_is_cut_but_keeps_its_recovery_tag(h):
    h.offer(1, title="T" * 1000)

    h.process()

    title = h.cups.submitted[0]["title"]
    assert len(title) < 215 and title.endswith("[ri-1]")


def test_a_job_with_no_copies_field_prints_one(h):
    h.api.offered.append({"id": 1, "queue_name": "Zebra", "title": "x"})

    h.process()

    assert h.cups.submitted[0]["copies"] == 1


# ------------------------------------------------------------- claim only what can print now

@pytest.mark.parametrize("state", ["offline", "error"])
def test_a_job_for_an_unready_printer_is_left_waiting_not_claimed(h, state):
    h.cups.queues["Zebra"].state = state
    h.offer(1)

    h.process()

    assert h.api.claims == [] and h.api.results == [] and h.cups.submitted == []

    h.cups.queues["Zebra"].state = "idle"  # the printer comes back: now it prints
    h.process()
    assert h.api.results == [(1, "printed", None)]


def test_a_job_for_a_queue_this_device_does_not_have_is_left_alone(h):
    h.offer(1, queue="Ghost")

    h.process()

    assert h.api.claims == []


def test_a_printer_that_is_busy_printing_still_accepts_jobs(h):
    h.cups.queues["Zebra"].state = "printing"
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)]


def test_one_job_at_a_time_per_queue_but_different_queues_in_parallel(h):
    h.cups.add_queue("Laser")
    h.cups.script["Zebra"] = [PROCESSING, PROCESSING, (JOB_COMPLETED, None)]
    h.offer(1, queue="Zebra")
    h.offer(2, queue="Zebra")
    h.offer(3, queue="Laser")

    h.process()

    assert sorted(h.api.claims) == [1, 3]  # job 2 waits behind job 1 on the same queue
    h.api.offered = [j for j in h.api.offered if j["id"] == 2]
    h.process()
    assert sorted(r[0] for r in h.api.results) == [1, 2, 3]


# -------------------------------------------------------------------- website says no (s.3.6)

@pytest.mark.parametrize("error", [Conflict("taken"), NotFound("gone")])
def test_a_job_that_cannot_be_claimed_is_skipped_silently(h, error):
    h.api.claim_errors[1] = [error]
    h.offer(1)

    h.process()

    assert h.cups.submitted == [] and h.api.results == [] and h.ledger.get(1) is None


def test_a_file_that_was_cleaned_up_fails_the_job_with_a_clear_message(h):
    h.api.payload_errors[1] = [Gone("cleaned")]
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "no longer available" in error and h.cups.submitted == []


@pytest.mark.parametrize("error", [Conflict("claim lost"), NotFound("gone")])
def test_losing_the_claim_before_printing_prints_nothing_and_forgets_the_job(h, error):
    h.api.payload_errors[1] = [error]
    h.offer(1)

    h.process()

    assert h.cups.submitted == [] and h.api.results == [] and h.ledger.get(1) is None


def test_a_failed_download_is_retried_then_succeeds(h):
    h.api.payload_errors[1] = [Transient("blip"), Transient("blip")]
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)]


def test_a_download_that_keeps_failing_prints_nothing_and_lets_the_claim_lapse(h):
    h.api.payload_errors[1] = [Transient("down")] * 3
    h.offer(1)

    h.process()

    assert h.cups.submitted == [] and h.api.results == [] and h.ledger.get(1) is None  # re-offered later, claimable again



# -------------------------------------------------------------------------- failures (s.3.5)

def test_cups_rejecting_the_job_fails_it_with_cups_message_and_holds_the_printer_back(h):
    h.cups.submit_error = CupsError("The print system rejected the job for 'Zebra': bad format")
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "bad format" in error
    # the printer is shown offline for a couple of minutes instead of failing every job the same way
    assert h.station.heartbeat_payload()["print_printers"][0]["state"] == "offline"
    h.cups.submit_error = None
    h.offer(2)
    h.api.offered = [j for j in h.api.offered if j["id"] == 2]
    h.process()
    assert h.api.claims == [1]  # job 2 not claimed during the hold-off

    h.vt.now += 121  # ...and tried again once it has passed
    h.process()
    assert h.api.results[-1] == (2, "printed", None)


def test_the_hold_off_after_a_failure_lapses_by_itself_and_is_forgotten_on_success(h):
    h.station._penalty["Zebra"] = (100.0, "earlier failure")
    h.offer(1)

    h.vt.now = 50
    h.process()
    assert h.api.claims == []  # still held back

    h.vt.now = 101
    h.process()
    assert h.api.results == [(1, "printed", None)] and "Zebra" not in h.station._penalty


def test_an_aborted_job_is_reported_failed_with_the_reason(h):
    h.cups.script["Zebra"] = [(JOB_ABORTED, None)]
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "aborted" in error and "aborted-by-system" in error


def test_a_job_cancelled_in_cups_is_reported_failed(h):
    h.cups.script["Zebra"] = [(JOB_CANCELED, None)]
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "cancelled" in error


def test_a_job_cups_lost_track_of_fails_with_advice(h):
    h.cups.script["Zebra"] = [PROCESSING]
    h.cups.on_poll = lambda queue, polls: h.cups.jobs.clear() if polls == 2 else None
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "lost track" in error


def test_a_printer_that_goes_offline_mid_job_fails_it_cancels_it_and_says_why(h):
    h.cups.script["Zebra"] = [PROCESSING]  # never finishes

    def printer_dies(queue, polls):
        if polls == 3:
            h.cups.queues["Zebra"].state = "offline"
            h.cups.queues["Zebra"].message = "Printer not reachable: The printer may not exist or is unavailable at this time"

    h.cups.on_poll = printer_dies
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "not reachable" in error
    assert h.cups.cancelled and h.cups.cancelled[0][0] == "Zebra"  # so it can't print later when the printer returns
    assert h.station.heartbeat_payload()["print_printers"][0]["state"] == "offline"


def test_a_brief_offline_blip_shorter_than_the_grace_period_does_not_fail_the_job(h):
    h.cups.script["Zebra"] = [PROCESSING, PROCESSING, PROCESSING, (JOB_COMPLETED, None)]

    def blip(queue, polls):
        h.cups.queues["Zebra"].state = "offline" if polls == 2 else "idle"

    h.cups.on_poll = blip
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)] and h.cups.cancelled == []


def test_a_job_with_no_progress_is_cancelled_after_the_stall_time(h):
    h.cups.script["Zebra"] = [PROCESSING]
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "no progress for 5 minutes" in error
    assert h.cups.cancelled and 300 <= h.vt.now < 330


def test_a_slow_job_that_keeps_making_progress_is_never_called_stalled(tmp_path):
    h = Harness(tmp_path, job_poll=30)  # one more page every 30 s for 20 minutes: far beyond the 5-minute stall time
    h.cups.add_queue("Zebra")
    h.cups.script["Zebra"] = [(JOB_PROCESSING, n) for n in range(40)] + [(JOB_COMPLETED, 40)]
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)] and h.cups.cancelled == []
    assert h.vt.now > 1000


def test_a_job_is_cancelled_at_the_absolute_time_limit_even_if_it_keeps_progressing(tmp_path):
    h = Harness(tmp_path, max_runtime=600)
    h.cups.add_queue("Zebra")
    h.cups.script["Zebra"] = [(JOB_PROCESSING, n) for n in range(10_000)]
    h.offer(1)

    h.process()

    (_, status, error), = h.api.results
    assert status == "failed" and "still not finished after 10 minutes" in error and h.cups.cancelled


def test_cups_being_briefly_unreachable_while_a_job_runs_does_not_fail_it(h):
    h.cups.script["Zebra"] = [PROCESSING, PROCESSING, PROCESSING, (JOB_COMPLETED, None)]

    def hiccup(queue, polls):
        h.cups.unreachable = polls == 2

    h.cups.on_poll = hiccup
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)]


# ----------------------------------------------------------------------------- keep-alive

def test_a_long_print_keeps_its_claim_alive_at_half_the_remaining_time(tmp_path):
    h = Harness(tmp_path, job_poll=30)  # a 20-minute print
    h.cups.add_queue("Zebra")
    h.cups.script["Zebra"] = [(JOB_PROCESSING, n) for n in range(40)] + [(JOB_COMPLETED, 40)]
    h.offer(1)

    h.process()

    times = [(when - START).total_seconds() for job, when in h.api.claim_times]
    gaps = [b - a for a, b in zip(times, times[1:])]
    assert len(times) >= 7 and all(job == 1 for job, _ in h.api.claim_times)  # the claim, then keep-alives
    assert all(140 <= gap <= 165 for gap in gaps)  # ~half of the 5-minute window each time
    assert h.api.results == [(1, "printed", None)]


def test_keep_alives_stop_when_the_job_ends(h):
    h.offer(1)
    h.process()
    count = len(h.api.claims)

    h.run(h.vt.sleep(3600))  # an hour of virtual time with nothing in flight

    assert len(h.api.claims) == count


def test_if_the_website_drops_the_claim_the_cups_job_is_cancelled_and_nothing_is_reported(tmp_path):
    h = Harness(tmp_path, job_poll=30)
    h.cups.add_queue("Zebra")
    h.cups.script["Zebra"] = [(JOB_PROCESSING, n) for n in range(400)] + [(JOB_COMPLETED, None)]
    h.api.claim_errors[1] = [None, Conflict("cancelled by staff")]  # claim ok, first keep-alive refused
    h.offer(1)

    h.process()

    assert h.cups.cancelled and h.api.results == [] and h.ledger.get(1) is None


def test_a_keep_alive_that_hits_a_network_error_just_tries_again(tmp_path):
    h = Harness(tmp_path, job_poll=30)
    h.cups.add_queue("Zebra")
    h.cups.script["Zebra"] = [(JOB_PROCESSING, n) for n in range(30)] + [(JOB_COMPLETED, 30)]
    h.api.claim_errors[1] = [None, Transient("blip")]
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)] and len(h.api.claims) >= 3


# ------------------------------------------------------------------- never lose a result

def test_the_result_is_retried_through_an_outage_with_backoff(h):
    h.api.result_errors[1] = [Transient("down"), RateLimited("slow"), Transient("down"), None]
    h.offer(1)

    h.process()

    assert h.api.results == [(1, "printed", None)]
    assert h.ledger.get(1)["state"] == REPORTED
    assert h.vt.now >= 2 + 4 + 8  # 2, 4, 8 second backoff


@pytest.mark.parametrize("error", [Conflict("claim expired"), NotFound("gone")])
def test_a_result_the_website_refuses_is_dropped_not_retried(h, error):
    h.api.result_errors[1] = [error]
    h.offer(1)

    h.process()

    assert h.api.results == [] and h.ledger.get(1)["state"] == REPORTED


def test_the_outcome_is_on_disk_before_the_website_is_told(h):
    seen = []
    original = h.api.result

    async def spy(job_id, status, error=None):
        seen.append(h.ledger.get(job_id)["state"])
        return await original(job_id, status, error)

    h.api.result = spy
    h.offer(1)

    h.process()

    assert seen == [FINISHED]  # a crash right here would still report on restart


# --------------------------------------------------------------------------------- revoked

def test_a_401_on_claim_wipes_the_device_once(h):
    h.api.claim_errors[1] = [Revoked("revoked")]
    h.offer(1)
    h.offer(2)

    h.process()

    assert h.revoked == 1 and h.wake.stopped == 1 and h.station._stopped


def test_a_401_while_reporting_a_result_wipes_the_device(h):
    h.api.result_errors[1] = [Revoked("revoked")]
    h.offer(1)

    h.process()

    assert h.revoked == 1


def test_a_401_on_the_heartbeat_loop_and_job_loop_wipes_once_between_them(h):
    h.api.jobs_error = Revoked("revoked")
    h.run(h.station.refresh_queues())

    async def both():
        await asyncio.gather(h.station._heartbeat_loop(), h.station._job_loop())

    async def heartbeat_revoked():
        async def failing(payload):
            raise Revoked("revoked")

        h.api.heartbeat = failing
        await both()

    h.run(heartbeat_revoked())

    assert h.revoked == 1


# ------------------------------------------------------------------- never print twice (s.3.7)

def test_a_job_we_already_finished_is_not_printed_again_when_the_website_reoffers_it(h):
    h.ledger.put(1, state=FINISHED, result="printed", error=None, queue="Zebra")
    h.offer(1)

    h.process()

    assert h.cups.submitted == []  # nothing new on paper
    assert h.api.claims == [1] and h.api.results == [(1, "printed", None)]  # but the website learns it printed
    assert h.ledger.get(1)["state"] == REPORTED


def test_a_reoffered_job_whose_outcome_we_reported_is_settled_again_without_printing(h):
    h.ledger.put(1, state=REPORTED, result="failed", error="Out of labels", queue="Zebra")
    h.offer(1)

    h.process()

    assert h.cups.submitted == [] and h.api.results == [(1, "failed", "Out of labels")]


def test_a_reoffered_job_we_cannot_reclaim_is_left_alone(h):
    h.ledger.put(1, state=REPORTED, result="printed", queue="Zebra")
    h.api.claim_errors[1] = [Conflict("not claimable")]
    h.offer(1)

    h.process()

    assert h.cups.submitted == [] and h.api.results == []


def test_a_job_handed_to_cups_before_a_crash_is_resolved_from_cups_not_reprinted(h):
    h.ledger.put(1, state=SUBMITTED, queue="Zebra", cups_job_id=77)
    h.cups.jobs[77] = {"queue": "Zebra", "title": "Pallet label [ri-1]", "polls": 0, "state": JOB_PROCESSING}
    h.offer(1)

    h.process()

    assert h.cups.submitted == [] and h.api.results == [(1, "printed", None)]


# ----------------------------------------------------------------------------- recovery

def test_recovery_reports_an_outcome_that_was_never_delivered(h):
    h.ledger.put(1, state=FINISHED, result="printed", error=None)

    h.run(h.station.recover())

    assert h.api.results == [(1, "printed", None)] and h.ledger.get(1)["state"] == REPORTED


def test_recovery_finds_a_job_that_reached_cups_before_the_crash_by_its_tag(h):
    h.ledger.put(1, state=CLAIMED, queue="Zebra")  # crashed before CUPS' job id was recorded
    h.cups.jobs[200] = {"queue": "Zebra", "title": "Pallet label [ri-1]", "polls": 0, "state": JOB_COMPLETED}

    h.run(h.station.recover())

    assert h.api.results == [(1, "printed", None)] and h.cups.submitted == []


def test_recovery_of_a_job_that_never_reached_cups_fails_it_with_advice_instead_of_guessing(h):
    h.ledger.put(1, state=CLAIMED, queue="Zebra")

    h.run(h.station.recover())

    (_, status, error), = h.api.results
    assert status == "failed" and "restarted" in error and "print again" in error and h.cups.submitted == []


def test_recovery_reports_failure_for_a_job_cups_aborted_meanwhile(h):
    h.ledger.put(1, state=SUBMITTED, queue="Zebra", cups_job_id=5)
    h.cups.jobs[5] = {"queue": "Zebra", "title": "x", "polls": 0, "state": JOB_PROCESSING}
    h.cups.script["Zebra"] = [(JOB_ABORTED, None)]

    h.run(h.station.recover())

    assert h.api.results[0][:2] == (1, "failed")


def test_recovery_keeps_following_a_job_that_is_still_printing_then_reports_it(h):
    h.ledger.put(1, state=SUBMITTED, queue="Zebra", cups_job_id=5)
    h.cups.jobs[5] = {"queue": "Zebra", "title": "x", "polls": 0, "state": JOB_PROCESSING}
    h.cups.script["Zebra"] = [PROCESSING, PROCESSING, (JOB_COMPLETED, None)]

    async def scenario():
        await h.station.recover()
        assert "Zebra" in h.station._busy  # the queue is held while it finishes
        await h.station.drain()

    h.run(scenario())

    assert h.api.results == [(1, "printed", None)] and "Zebra" not in h.station._busy


def test_recovery_with_cups_unreachable_leaves_the_entry_for_later(h):
    h.ledger.put(1, state=SUBMITTED, queue="Zebra", cups_job_id=5)
    h.cups.unreachable = True

    h.run(h.station.recover())

    assert h.api.results == [] and h.ledger.get(1)["state"] == SUBMITTED


def test_recovery_prunes_old_reported_entries(h):
    h.ledger.put(1, state=REPORTED)
    h.vt.now = 8 * 24 * 3600

    h.run(h.station.recover())

    assert h.ledger.get(1) is None


# ------------------------------------------------------------------------------- the loops

def test_the_job_loop_polls_fast_without_the_wake_socket_and_slowly_with_it(tmp_path):
    def poll_count(connected):
        h = Harness(tmp_path / str(connected))
        calls = []
        original = h.api.jobs

        async def counting():
            calls.append(1)
            return await original()

        h.api.jobs = counting
        h.wake.connected = connected

        async def scenario():
            task = asyncio.ensure_future(h.station._job_loop())
            await h.vt.sleep(180)
            task.cancel()

        h.run(scenario())
        return len(calls)

    assert poll_count(False) >= 40 and poll_count(True) <= 5  # ~every 4 s versus ~every 45 s


def test_a_wake_up_from_the_socket_thread_triggers_an_immediate_poll(h):
    h.wake.connected = True  # slow polling: only a wake-up can make it prompt
    calls = []
    original = h.api.jobs

    async def counting():
        calls.append(h.vt.now)
        return await original()

    h.api.jobs = counting

    async def scenario():
        h.station._loop = asyncio.get_running_loop()
        task = asyncio.ensure_future(h.station._job_loop())
        await h.vt.sleep(10)
        thread = threading.Thread(target=h.station._wake_from_thread)  # a real thread, as the wake socket's is
        thread.start()
        thread.join()
        await h.vt.sleep(1)
        task.cancel()
        return list(calls)

    times = h.run(scenario())

    assert len(times) == 2 and times[1] <= 12  # polled at start, then right after the wake-up (not 45 s later)


def test_the_heartbeat_loop_beats_on_change_and_at_least_every_45_seconds(h):
    beats = []
    original = h.api.heartbeat

    async def counting(payload):
        beats.append(h.vt.now)
        return await original(payload)

    h.api.heartbeat = counting

    async def scenario():
        await h.station.refresh_queues()
        task = asyncio.ensure_future(h.station._heartbeat_loop())
        await h.vt.sleep(100)
        h.cups.queues["Zebra"].state = "error"  # a fault appears at t=100
        await h.station.refresh_queues()
        await h.vt.sleep(10)
        task.cancel()

    h.run(scenario())

    assert beats[0] == 0 and 44 <= beats[1] <= 48 and 90 <= beats[2] <= 96  # the steady cadence
    assert any(100 <= t <= 103 for t in beats)  # and the fault went out within seconds, not 45 s later


def test_a_flapping_printer_cannot_flood_the_website_with_heartbeats(h):
    beats = []
    original = h.api.heartbeat

    async def counting(payload):
        beats.append(h.vt.now)
        return await original(payload)

    h.api.heartbeat = counting

    async def scenario():
        await h.station.refresh_queues()
        task = asyncio.ensure_future(h.station._heartbeat_loop())
        for flap in range(20):
            h.cups.queues["Zebra"].state = "error" if flap % 2 else "idle"
            await h.station.refresh_queues()
            await h.vt.sleep(0.2)
        task.cancel()

    h.run(scenario())

    assert len(beats) <= 4  # at most one every couple of seconds


def test_run_idles_while_unpaired_then_works_and_stops_cleanly(h):
    h.paired = False
    h.offer(1)

    async def scenario():
        task = asyncio.ensure_future(h.station.run())
        await h.vt.sleep(60)
        assert h.api.claims == [] and h.api.heartbeats == []  # not paired: touches nothing
        h.paired = True
        await h.vt.sleep(60)
        task.cancel()
        with pytest.raises(asyncio.CancelledError):
            await task

    h.run(scenario())

    assert h.api.results == [(1, "printed", None)] and h.api.heartbeats
    assert h.wake.stopped >= 1  # the wake socket is closed on the way out


def test_run_recovers_unfinished_jobs_before_polling(h):
    h.ledger.put(9, state=FINISHED, result="failed", error="old failure")

    async def scenario():
        task = asyncio.ensure_future(h.station.run())
        await h.vt.sleep(5)
        task.cancel()

    h.run(scenario())

    assert h.api.results[0] == (9, "failed", "old failure")


def test_status_summarises_the_stations_state(h):
    h.offer(1)
    h.process()

    status = h.station.status()["print_station"]

    assert status["queues"][0]["queue_name"] == "Zebra"
    assert status["realtime_connected"] is False and status["busy"] == [] and status["last_error"] is None
    assert status["last_poll"] is not None


def test_poll_errors_are_recorded_for_the_status_not_raised(h):
    h.api.jobs_error = Transient("server down")

    async def scenario():
        task = asyncio.ensure_future(h.station._job_loop())
        await h.vt.sleep(5)
        task.cancel()

    h.run(scenario())

    assert h.station.status()["print_station"]["last_error"] == "server down"
