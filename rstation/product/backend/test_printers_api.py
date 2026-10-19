import asyncio
from datetime import datetime

import pytest
from fastapi import HTTPException

from . import ipp, testpage
from .cups_client import IDLE, OFFLINE, CupsClient, _without_credentials
from .fakes import FakeIppServer, ipp_response
from .ipp_fixtures import PRINT_JOB
from .printers_api import build_router

run = asyncio.run


def _group(name, **extra):
    attrs = {"printer-name": name, "printer-state": 3, "printer-state-reasons": "none", "printer-state-message": "",
             "printer-is-accepting-jobs": True, **extra}
    return (ipp.PRINTER_ATTRIBUTES, attrs)


def _server(*groups):
    server = FakeIppServer()
    server.responses[ipp.CUPS_GET_PRINTERS] = ipp_response(0, [(ipp.OPERATION_ATTRIBUTES, {}), *groups])
    server.responses[ipp.PRINT_JOB] = PRINT_JOB
    return server


def _endpoint(router, path):
    return next(r.endpoint for r in router.routes if r.path == path)


def test_details_include_model_device_and_queue_length():
    server = _server(_group("Zebra", **{"printer-make-and-model": "Zebra ZD421", "device-uri": "usb://Zebra/ZD421",
                                        "printer-location": "Dock", "queued-job-count": 2}),
                     _group("Down", **{"printer-is-accepting-jobs": False}))

    down, zebra = run(CupsClient(transport=server).printer_details())  # sorted by name

    assert (zebra.model, zebra.device, zebra.location, zebra.jobs, zebra.state) == ("Zebra ZD421", "usb://Zebra/ZD421", "Dock", 2, IDLE)
    assert (down.state, down.accepting, down.model, down.jobs) == (OFFLINE, False, None, 0)


@pytest.mark.parametrize("uri, expected", [
    ("ipp://user:secret@printer.local/ipp/print", "ipp://printer.local/ipp/print"),
    ("socket://10.0.0.5:9100", "socket://10.0.0.5:9100"),
    ("smb://dom;me:pw@server/share", "smb://server/share"),
    ("", None), (None, None),
])
def test_device_uri_credentials_are_never_shown(uri, expected):
    assert _without_credentials(uri) == expected


def test_test_page_is_a_wellformed_pdf_naming_the_queue():
    pdf = testpage.build("Zebra (dock)", "rstation-1", datetime(2026, 10, 18, 9, 30))

    assert pdf.startswith(b"%PDF-1.4") and pdf.rstrip().endswith(b"%%EOF")
    assert b"Printer: Zebra \\(dock\\)" in pdf
    startxref = int(pdf.rsplit(b"startxref\n", 1)[1].split()[0])
    assert pdf[startxref:startxref + 4] == b"xref"  # the cross-reference table is where it says it is


def test_listing_reports_cups_down_as_data_not_an_http_error():
    import httpx
    server = FakeIppServer()
    server.connect_error = httpx.ConnectError("refused")
    body = run(_endpoint(build_router(CupsClient(transport=server)), "/api/local/printers")())
    assert body["printers"] == [] and "CUPS" in body["error"]


def test_test_page_prints_to_a_known_queue_and_rejects_an_unknown_one():
    server = _server(_group("Zebra"))
    endpoint = _endpoint(build_router(CupsClient(transport=server)), "/api/local/printers/{name}/test-page")

    assert run(endpoint("Zebra"))["job_id"] == 0x933
    job = [r for r in server.requests if r["operation"] == ipp.PRINT_JOB][0]
    assert job["path"] == "/printers/Zebra"

    with pytest.raises(HTTPException) as unknown:
        run(endpoint("Nope"))
    assert unknown.value.status_code == 404
