import json

from .ledger import CLAIMED, FINISHED, REPORTED, RETAIN_SECONDS, SUBMITTED, Ledger


def test_entries_survive_a_restart(tmp_path):
    Ledger(tmp_path).put(7, state=SUBMITTED, queue="Zebra", cups_job_id=42)

    again = Ledger(tmp_path)  # a new process reading the same directory

    assert again.get(7)["state"] == SUBMITTED and again.get(7)["cups_job_id"] == 42


def test_put_merges_fields_and_stamps_the_time(tmp_path):
    clock = iter([100.0, 200.0])
    ledger = Ledger(tmp_path, clock=lambda: next(clock))

    ledger.put(1, state=CLAIMED, queue="Q")
    entry = ledger.put(1, state=FINISHED, result="printed")

    assert entry == {"state": FINISHED, "queue": "Q", "result": "printed", "updated": 200.0}


def test_unknown_job_is_none_and_get_returns_a_copy(tmp_path):
    ledger = Ledger(tmp_path)
    ledger.put(1, state=CLAIMED)

    assert ledger.get(2) is None
    ledger.get(1)["state"] = "tampered"
    assert ledger.get(1)["state"] == CLAIMED


def test_unsettled_lists_everything_not_yet_reported_oldest_first(tmp_path):
    times = iter(range(1, 20))
    ledger = Ledger(tmp_path, clock=lambda: float(next(times)))
    ledger.put(3, state=CLAIMED)
    ledger.put(1, state=FINISHED, result="printed")
    ledger.put(2, state=REPORTED)

    assert [job_id for job_id, _ in ledger.unsettled()] == [3, 1]


def test_forget_removes_an_entry_so_the_job_can_be_claimed_afresh(tmp_path):
    ledger = Ledger(tmp_path)
    ledger.put(1, state=CLAIMED)

    ledger.forget(1)
    ledger.forget(99)  # forgetting something unknown is harmless

    assert ledger.get(1) is None and Ledger(tmp_path).get(1) is None


def test_prune_drops_only_old_reported_entries(tmp_path):
    now = [0.0]
    ledger = Ledger(tmp_path, clock=lambda: now[0])
    ledger.put(1, state=REPORTED)
    ledger.put(2, state=FINISHED, result="printed")  # unreported: must never be pruned, however old
    now[0] = RETAIN_SECONDS + 1
    ledger.put(3, state=REPORTED)

    ledger.prune()

    assert ledger.get(1) is None and ledger.get(2) is not None and ledger.get(3) is not None


def test_a_corrupt_file_starts_empty_instead_of_blocking_printing(tmp_path):
    (tmp_path / "ledger.json").write_text("{ this is not json")

    ledger = Ledger(tmp_path)

    assert ledger.get(1) is None
    ledger.put(1, state=CLAIMED)  # and it recovers by rewriting
    assert json.loads((tmp_path / "ledger.json").read_text())["1"]["state"] == CLAIMED


def test_writes_are_atomic_and_leave_no_temp_file(tmp_path):
    ledger = Ledger(tmp_path / "nested" / "dir")  # the directory is created on first write

    ledger.put(1, state=CLAIMED)

    assert sorted(p.name for p in (tmp_path / "nested" / "dir").iterdir()) == ["ledger.json"]
