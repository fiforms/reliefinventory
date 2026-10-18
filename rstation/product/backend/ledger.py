"""The print server's record of what it has done with each job, kept on disk
so a restart or power cut can never print a job twice or lose a result
(rstation/PRINT_CLIENT_SPEC.md section 3.7).

One JSON file under /data (the only persistent area on the device), rewritten
atomically on every change. A job moves through:

    claimed    claimed on the website, nothing sent to CUPS yet
    submitted  handed to CUPS (cups_job_id recorded)
    finished   outcome known (result/error) but the website hasn't been told
    reported   the website acknowledged the outcome

`reported` entries are kept for a while (so a job the website somehow offers
again is recognised, not re-printed) and then pruned.
"""
import json
import os
import time
from pathlib import Path

DEFAULT_DIR = Path("/data/print-station")
RETAIN_SECONDS = 7 * 24 * 3600

CLAIMED, SUBMITTED, FINISHED, REPORTED = "claimed", "submitted", "finished", "reported"


class Ledger:
    def __init__(self, directory: Path = DEFAULT_DIR, clock=time.time):
        self.directory = Path(directory)
        self._file = self.directory / "ledger.json"
        self._clock = clock
        self._entries: dict[str, dict] | None = None

    def _load(self) -> dict[str, dict]:
        if self._entries is None:
            try:
                self._entries = json.loads(self._file.read_text())
            except (OSError, ValueError):
                # Missing or corrupt: start empty rather than refuse to print.
                self._entries = {}
        return self._entries

    def _save(self) -> None:
        self.directory.mkdir(parents=True, exist_ok=True)
        temp = self._file.with_suffix(".tmp")
        temp.write_text(json.dumps(self._entries))
        os.replace(temp, self._file)  # atomic: a crash leaves the old file or the new, never half of one

    def get(self, job_id: int) -> dict | None:
        entry = self._load().get(str(job_id))
        return dict(entry) if entry else None

    def put(self, job_id: int, **fields) -> dict:
        entries = self._load()
        entry = entries.setdefault(str(job_id), {})
        entry.update(fields)
        entry["updated"] = self._clock()
        self._save()
        return dict(entry)

    def forget(self, job_id: int) -> None:
        """Drops an entry — for a job we never printed anything for, so the website may
        offer it again and we must be free to claim it."""
        if self._load().pop(str(job_id), None) is not None:
            self._save()

    def unsettled(self) -> list[tuple[int, dict]]:
        """Entries the website has not yet been told about, oldest first."""
        pending = [(int(job_id), dict(entry)) for job_id, entry in self._load().items() if entry.get("state") != REPORTED]
        return sorted(pending, key=lambda item: item[1].get("updated", 0))

    def prune(self) -> None:
        entries = self._load()
        cutoff = self._clock() - RETAIN_SECONDS
        stale = [job_id for job_id, entry in entries.items() if entry.get("state") == REPORTED and entry.get("updated", 0) < cutoff]
        if stale:
            for job_id in stale:
                del entries[job_id]
            self._save()
