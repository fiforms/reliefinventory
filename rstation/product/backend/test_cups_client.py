import asyncio

import httpx
import pytest

from . import ipp
from .cups_client import ERROR, IDLE, OFFLINE, PRINTING, CupsClient, CupsError, classify_printer
from .fakes import FakeIppServer, ipp_response
from .ipp_fixtures import JOB_COMPLETED, JOB_NOT_FOUND, PRINT_JOB, PRINTER_UNREACHABLE

run = asyncio.run


# The first six rows are what real CUPS 2.4 reported for loopback queues (see ipp_fixtures.py and
# rstation/PRINT_CLIENT_SPEC.md section 2); the rest are standard IPP reasons.
@pytest.mark.parametrize("state, reasons, accepting, message, expected", [
    (3, "none", True, None, (IDLE, None)),
    (4, "none", True, None, (PRINTING, None)),
    (5, "paused", True, "Paused", (OFFLINE, "Printer paused")),
    (3, "none", False, "Rejecting Jobs", (OFFLINE, "Printer is not accepting jobs")),
    (4, "none", True, "The printer may not exist or is unavailable at this time.",
     (OFFLINE, "Printer not reachable: The printer may not exist or is unavailable at this time")),
    (4, "connecting-to-device", True, 'Unable to locate printer "no-such-host.invalid".',
     (OFFLINE, 'Printer not reachable: Unable to locate printer "no-such-host.invalid"')),
    (4, "connecting-to-device", True, "Connected to printer.", (PRINTING, None)),  # transient, not a fault
    (5, ["media-empty-error"], True, None, (ERROR, "Out of paper or labels")),
    (5, ["media-empty-report"], True, None, (ERROR, "Out of paper or labels")),  # stopped *because of* it
    (3, ["media-empty-report"], True, None, (IDLE, None)),  # merely informational on a working printer
    (5, ["media-jam-error"], True, None, (ERROR, "Paper or label jam")),
    (5, ["toner-empty-error"], True, None, (ERROR, "Out of toner")),
    (5, ["cover-open-error"], True, None, (ERROR, "Cover open")),
    (3, ["media-low-warning", "toner-low-report"], True, None, (IDLE, None)),  # warnings don't make it unhealthy
    (5, ["offline-report"], True, None, (OFFLINE, "Printer offline")),
    (5, ["connecting-to-device", "offline-report"], True, None, (OFFLINE, "Printer offline")),
    (5, "none", True, "Something odd happened", (ERROR, "Something odd happened")),
    (5, "none", True, None, (ERROR, "Printer stopped")),
    # an old "unreachable" message on an idle queue (no job being attempted) is stale, not a fault
    (3, "none", True, "The printer may not exist or is unavailable at this time.", (IDLE, None)),
    (None, None, None, None, (IDLE, None)),  # missing attributes: assume fine rather than raise
])
def test_classify_printer(state, reasons, accepting, message, expected):
    assert classify_printer(state, reasons, accepting, message) == expected


def _printer_group(name, state=3, reasons="none", message="", accepting=True):
    return (ipp.PRINTER_ATTRIBUTES, {"printer-name": name, "printer-state": state, "printer-state-reasons": reasons,
                                     "printer-state-message": message, "printer-is-accepting-jobs": accepting})


def _client(server: FakeIppServer) -> CupsClient:
    return CupsClient(transport=server)


def test_printers_are_listed_sorted_and_classified():
    server = FakeIppServer()
    server.responses[ipp.CUPS_GET_PRINTERS] = ipp_response(0, [
        (ipp.OPERATION_ATTRIBUTES, {"attributes-charset": "utf-8"}),
        _printer_group("Zebra", 5, "media-empty-error"),
        _printer_group("Brother", 3),
        _printer_group("Down", 4, "none", "The printer may not exist or is unavailable at this time."),
    ])

    printers = run(_client(server).printers())

    assert [(p.name, p.state) for p in printers] == [("Brother", IDLE), ("Down", OFFLINE), ("Zebra", ERROR)]
    request = server.requests[0]
    assert request["path"] == "/" and request["operation"] == ipp.CUPS_GET_PRINTERS
    assert "printer-state-reasons" in request["attributes"]["requested-attributes"]


def test_no_queues_at_all_is_an_empty_list_not_an_error():
    server = FakeIppServer()
    server.responses[ipp.CUPS_GET_PRINTERS] = ipp_response(0x0406, [(ipp.OPERATION_ATTRIBUTES, {})])

    assert run(_client(server).printers()) == []


def test_cups_being_down_is_a_cups_error_with_a_readable_message():
    server = FakeIppServer()
    server.connect_error = httpx.ConnectError("connection refused")
    with pytest.raises(CupsError, match="Could not reach the print system"):
        run(_client(server).printers())

    server.connect_error = None
    server.http_status = 500
    with pytest.raises(CupsError, match="HTTP 500"):
        run(_client(server).printers())

    server.http_status = 200
    server.responses[ipp.CUPS_GET_PRINTERS] = b"junk"
    with pytest.raises(CupsError, match="Unreadable"):
        run(_client(server).printers())


def test_submit_sends_a_correct_print_job_and_returns_the_cups_job_id():
    server = FakeIppServer()
    server.responses[ipp.PRINT_JOB] = PRINT_JOB
    pdf = b"%PDF-1.4 the document"

    job_id = run(_client(server).submit("Zebra ZD 421", pdf, "Pallet label [ri-9]", copies=3))

    request = server.requests[0]
    assert isinstance(job_id, int) and job_id > 0
    assert request["operation"] == ipp.PRINT_JOB
    assert request["path"] == "/printers/Zebra%20ZD%20421"  # the queue name is URL-quoted
    assert request["attributes"]["printer-uri"] == "ipp://localhost/printers/Zebra%20ZD%20421"
    assert request["attributes"]["job-name"] == "Pallet label [ri-9]"
    assert request["attributes"]["document-format"] == "application/pdf"
    assert request["attributes"]["requesting-user-name"] == "relief-print"
    assert request["job"]["copies"] == 3
    assert request["job"]["print-scaling"] == "none"  # actual size: the website already rendered the right page
    assert request["body"].endswith(pdf)  # the document follows the IPP header


def test_submit_clamps_copies_and_accepts_job_option_overrides():
    server = FakeIppServer()
    server.responses[ipp.PRINT_JOB] = PRINT_JOB

    run(_client(server).submit("Q", b"%PDF", "t", copies=0, job_options={"print-scaling": ipp.keyword("fit")}))

    assert server.requests[0]["job"]["copies"] == 1
    assert server.requests[0]["job"]["print-scaling"] == "fit"


def test_a_rejected_job_raises_with_cups_own_explanation():
    server = FakeIppServer()
    server.responses[ipp.PRINT_JOB] = ipp_response(0x0400, [(ipp.OPERATION_ATTRIBUTES, {"status-message": "Unsupported document-format"})])

    with pytest.raises(CupsError, match="rejected the job for 'Q'.*Unsupported document-format"):
        run(_client(server).submit("Q", b"x", "t"))


def test_job_reports_state_reasons_and_progress():
    server = FakeIppServer()
    server.responses[ipp.GET_JOB_ATTRIBUTES] = JOB_COMPLETED

    job = run(_client(server).job("Q", 5))

    assert job.completed and job.finished
    assert job.reasons == ["job-completed-successfully"]
    assert job.impressions == 1
    assert server.requests[0]["attributes"]["job-id"] == 5


def test_a_job_cups_has_forgotten_is_none():
    server = FakeIppServer()
    server.responses[ipp.GET_JOB_ATTRIBUTES] = JOB_NOT_FOUND

    assert run(_client(server).job("Q", 999999)) is None


@pytest.mark.parametrize("state, finished, completed", [
    (ipp.JOB_PENDING, False, False), (ipp.JOB_HELD, False, False), (ipp.JOB_PROCESSING, False, False),
    (ipp.JOB_CANCELED, True, False), (ipp.JOB_ABORTED, True, False), (ipp.JOB_COMPLETED, True, True),
])
def test_job_state_flags(state, finished, completed):
    server = FakeIppServer()
    server.responses[ipp.GET_JOB_ATTRIBUTES] = ipp_response(0, [(ipp.JOB_ATTRIBUTES, {"job-state": state, "job-state-reasons": "none"})])

    job = run(_client(server).job("Q", 1))

    assert (job.finished, job.completed) == (finished, completed)


def test_cancel_sends_the_owner_name_and_tolerates_a_job_that_is_already_over():
    server = FakeIppServer()
    for status in (0, 0x0406, 0x0404):  # ok, not-found, not-possible (already finished)
        server.responses[ipp.CANCEL_JOB] = ipp_response(status, [(ipp.OPERATION_ATTRIBUTES, {})])
        run(_client(server).cancel("Q", 7))

    assert server.requests[0]["attributes"]["job-id"] == 7
    assert server.requests[0]["attributes"]["requesting-user-name"] == "relief-print"

    server.responses[ipp.CANCEL_JOB] = ipp_response(0x0403, [(ipp.OPERATION_ATTRIBUTES, {"status-message": "not authorized"})])
    with pytest.raises(CupsError, match="not authorized"):
        run(_client(server).cancel("Q", 7))


def test_find_job_picks_the_newest_job_whose_name_carries_the_tag():
    server = FakeIppServer()
    server.responses[ipp.GET_JOBS] = ipp_response(0, [
        (ipp.OPERATION_ATTRIBUTES, {"attributes-charset": "utf-8"}),
        (ipp.JOB_ATTRIBUTES, {"job-id": 10, "job-name": "Pallet label [ri-9]", "job-state": ipp.JOB_COMPLETED}),
        (ipp.JOB_ATTRIBUTES, {"job-id": 11, "job-name": "Something else", "job-state": ipp.JOB_COMPLETED}),
        (ipp.JOB_ATTRIBUTES, {"job-id": 12, "job-name": "Pallet label [ri-9]", "job-state": ipp.JOB_PROCESSING}),
    ])

    found = run(_client(server).find_job("Q", "[ri-9]"))

    assert found.job_id == 12 and found.state == ipp.JOB_PROCESSING
    assert server.requests[0]["attributes"]["which-jobs"] == "all"
    assert server.requests[0]["attributes"]["my-jobs"] is True
    assert run(_client(server).find_job("Q", "[ri-404]")) is None


def test_real_unreachable_printer_bytes_classify_as_offline_through_the_client():
    server = FakeIppServer()
    server.responses[ipp.CUPS_GET_PRINTERS] = PRINTER_UNREACHABLE  # one real printer group, as CUPS sent it

    (printer,) = run(_client(server).printers())

    assert printer.state == OFFLINE and "may not exist or is unavailable" in printer.message
