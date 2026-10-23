"""Tests for the printer-setup root helper (loaded from its script — it is deliberately not
importable from the app package), the backend client that talks to it, the device-list
curation, and the Settings > Printers endpoints built on them."""
import asyncio
import importlib.machinery
import importlib.util
import json
import os
import stat
from pathlib import Path

import pytest
from fastapi import HTTPException

from .printer_setup import PrinterSetup, SetupError, address_to_uri, curate, queue_name
from .printers_api import PrinterAdd, build_router

run = asyncio.run

_product_root = Path(os.environ.get("PRODUCT_ROOT") or Path(__file__).parents[1])
_script = _product_root / "image/system/scripts/slide-announcer-printer-setup"
_loader = importlib.machinery.SourceFileLoader("printer_setup_helper", str(_script))
helper = importlib.util.module_from_spec(importlib.util.spec_from_loader(_loader.name, _loader))
_loader.exec_module(helper)

LPINFO = """\
Device: uri = file:/dev/null
\tclass = file
\tinfo = Unknown
\tmake-and-model = Unknown
\tdevice-id =
\tlocation =
Device: uri = dnssd://Brother%20MFC-9970CDW%20%5B3C2AF4%5D._ipp._tcp.local/?uuid=abc
\tclass = network
\tinfo = Brother MFC-9970CDW [3C2AF4]
\tmake-and-model = Brother MFC-9970CDW
\tdevice-id = MFG:Brother;MDL:MFC-9970CDW;CMD:PJL,PCL;SN:U63877A5J123456;
\tlocation = Front office
Device: uri = socket://10.0.0.50
\tclass = network
\tinfo = Brother MFC-9970CDW
\tmake-and-model = Brother MFC-9970CDW
\tdevice-id = MFG:Brother;MDL:MFC-9970CDW;SN:U63877A5J123456;
\tlocation =
Device: uri = ipp
\tclass = network
\tinfo = Internet Printing Protocol (ipp)
\tmake-and-model = Unknown
\tdevice-id =
\tlocation =
"""

# What a real network looked like: this Brother advertises only raw port 9100 over Bonjour, no IPP.
LPINFO_RAW_ONLY = """\
Device: uri = ipp
\tclass = network
\tinfo = Internet Printing Protocol (ipp)
\tmake-and-model = Unknown
\tdevice-id = 
\tlocation = 
Device: uri = dnssd://Brother%20MFC-9970CDW._pdl-datastream._tcp.local/
\tclass = network
\tinfo = Brother MFC-9970CDW
\tmake-and-model = Brother MFC-9970CDW
\tdevice-id = MFG:Brother;MDL:MFC-9970CDW;CMD:PCL;
\tlocation = 
"""


# --- the root helper

class FakeRun:
    """Stands in for subprocess: records commands, answers by program name."""

    def __init__(self, existing=(), lpinfo="", fail_add=False):
        self.existing = set(existing)
        self.lpinfo = lpinfo
        self.fail_add = fail_add
        self.calls = []

    def __call__(self, args, timeout):
        self.calls.append(args)
        program = args[0]
        if program == "lpstat":
            return (0 if args[2] in self.existing else 1), "", ""
        if program == "lpinfo":
            return 0, self.lpinfo, ""
        if program == "lpadmin" and args[1] == "-x":
            self.existing.discard(args[2])
            return 0, "", ""
        if program == "lpadmin":
            if self.fail_add:
                self.existing.add(args[2])  # a half-made queue the helper must clean up
                return 1, "", "lpadmin: Unable to\nget printer attributes"
            self.existing.add(args[2])
            return 0, "", ""
        raise AssertionError(f"unexpected command {args}")


def _code(h, request):
    try:
        return h.handle(request)["code"]
    except helper.Failure as failure:
        return failure.code


def test_parse_devices_reads_lpinfo_blocks():
    devices = helper.parse_devices(LPINFO)

    assert [d["uri"] for d in devices] == [
        "file:/dev/null", "dnssd://Brother%20MFC-9970CDW%20%5B3C2AF4%5D._ipp._tcp.local/?uuid=abc", "socket://10.0.0.50", "ipp"]
    assert devices[1]["make-and-model"] == "Brother MFC-9970CDW"
    assert devices[1]["device-id"].endswith("SN:U63877A5J123456;")
    assert devices[1]["location"] == "Front office"


def test_discover_returns_the_parsed_devices_and_listens_for_a_bounded_time():
    fake = FakeRun(lpinfo=LPINFO)

    result = helper.Helper(run=fake).handle({"op": "discover"})

    assert result["code"] == "ok" and len(result["devices"]) == 4
    assert fake.calls == [["lpinfo", "--timeout", str(helper.DISCOVER_TIMEOUT_SECONDS), "-l", "-v"]]


def test_add_creates_a_driverless_unshared_queue():
    fake = FakeRun()

    assert _code(helper.Helper(run=fake), {"op": "add", "name": "Front_Office", "uri": "ipp://10.0.0.50/ipp/print"}) == "ok"

    assert fake.calls[-1] == ["lpadmin", "-p", "Front_Office", "-E", "-v", "ipp://10.0.0.50/ipp/print",
                              "-m", "everywhere", "-o", "printer-is-shared=false"]


@pytest.mark.parametrize("driver, model", [
    ("generic-postscript", "drv:///sample.drv/generic.ppd"), ("generic-pcl", "drv:///sample.drv/laserjet.ppd")])
def test_add_can_use_one_of_the_fixed_generic_drivers(driver, model):
    fake = FakeRun()

    assert _code(helper.Helper(run=fake), {"op": "add", "name": "Lab", "uri": "socket://10.0.0.50:9100", "driver": driver}) == "ok"

    assert fake.calls[-1][fake.calls[-1].index("-m") + 1] == model


LPINFO_M = """\
everywhere IPP Everywhere
drv:///sample.drv/generic.ppd Generic PostScript Printer
foomatic-db-compressed-ppds:0/ppd/foomatic-ppd/Brother-MFC-9970CDW-pxlcolor.ppd Brother MFC-9970CDW Foomatic/pxlcolor (en)
brmfc9970cdw.ppd Brother MFC-9970CDW CUPS
foomatic-db-compressed-ppds:0/ppd/foomatic-ppd/Brother-HL-2060-pxlmono.ppd Brother HL-2060 Foomatic/pxlmono
"""


def test_drivers_search_matches_every_word_and_lists_the_shortest_name_first():
    fake = FakeRun(lpinfo=LPINFO_M)

    result = helper.Helper(run=fake).handle({"op": "drivers", "query": "brother 9970"})

    assert [d["id"] for d in result["drivers"]] == [
        "brmfc9970cdw.ppd", "foomatic-db-compressed-ppds:0/ppd/foomatic-ppd/Brother-MFC-9970CDW-pxlcolor.ppd"]
    assert result["total"] == 2 and fake.calls == [["lpinfo", "-m"]]


@pytest.mark.parametrize("query", ["", "   ", None, 5, "x" * 101])
def test_drivers_search_refuses_an_empty_or_odd_query(query):
    assert _code(helper.Helper(run=FakeRun(lpinfo=LPINFO_M)), {"op": "drivers", "query": query}) == "invalid_request"


def test_add_accepts_a_driver_id_the_system_lists_and_nothing_else():
    ppd = "brmfc9970cdw.ppd"
    fake = FakeRun(lpinfo=LPINFO_M)

    assert _code(helper.Helper(run=fake), {"op": "add", "name": "Lab", "uri": "socket://h", "driver": ppd}) == "ok"
    assert fake.calls[-1][fake.calls[-1].index("-m") + 1] == ppd

    fake = FakeRun(lpinfo=LPINFO_M)
    for bad in ("brmfc9970cdw", "-o", "lsb/usr/../../etc/passwd"):
        assert _code(helper.Helper(run=fake), {"op": "add", "name": "Lab2", "uri": "socket://h", "driver": bad}) == "invalid_driver"
    assert not any(c[0] == "lpadmin" for c in fake.calls)


@pytest.mark.parametrize("driver", ["", "everywhere ", "raw", "-o foo", "drv:///sample.drv/laserjet.ppd", None, ["everywhere"], 3])
def test_add_refuses_any_driver_that_is_neither_fixed_nor_listed_by_the_system(driver):
    fake = FakeRun()

    assert _code(helper.Helper(run=fake), {"op": "add", "name": "P", "uri": "socket://h", "driver": driver}) == "invalid_driver"
    assert not any(c[0] == "lpadmin" for c in fake.calls)


def test_add_never_touches_an_existing_queue():
    fake = FakeRun(existing={"Front_Office"})

    assert _code(helper.Helper(run=fake), {"op": "add", "name": "Front_Office", "uri": "ipp://10.0.0.99/ipp/print"}) == "exists"
    assert not any(c[0] == "lpadmin" for c in fake.calls)


def test_a_failed_add_leaves_no_queue_behind_and_reports_cups_message():
    fake = FakeRun(fail_add=True)

    with pytest.raises(helper.Failure) as caught:
        helper.Helper(run=fake).handle({"op": "add", "name": "Old_Brother", "uri": "socket://10.0.0.50"})

    assert caught.value.code == "add_failed"
    assert caught.value.detail == "lpadmin: Unable to get printer attributes"  # whitespace collapsed
    assert "Old_Brother" not in fake.existing


@pytest.mark.parametrize("name", ["", "-rf", "has space", "semi;colon", "a" * 64, "ünï", "../x", ".hidden", None, 5])
def test_add_rejects_names_that_could_be_options_or_paths(name):
    fake = FakeRun()

    assert _code(helper.Helper(run=fake), {"op": "add", "name": name, "uri": "ipp://h/ipp/print"}) == "invalid_name"
    assert fake.calls == []


@pytest.mark.parametrize("uri", [
    "file:///etc/shadow", "pipe:/bin/sh", "smb://host/share", "ipp", "ipp:/missing-slashes", "http://h/", "ipp://", "usb://",
    "rstationlp://", "rstationlp:/dev/usb/lp0", "ipp://h/has space", "ipp://h/\nx", "", None, "ipp://" + "h" * 600])
def test_add_rejects_schemes_and_shapes_that_arent_a_printer_connection(uri):
    fake = FakeRun()

    assert _code(helper.Helper(run=fake), {"op": "add", "name": "P", "uri": uri}) == "invalid_uri"
    assert fake.calls == []


@pytest.mark.parametrize("uri", [
    "ipp://10.0.0.50/ipp/print", "ipps://printer.local:631/ipp/print", "socket://10.0.0.50:9100", "lpd://10.0.0.50/queue",
    "dnssd://Brother%20MFC._ipp._tcp.local/?uuid=abc", "usb://Zebra/ZD421?serial=123",
    "rstationlp://SATO/WS408?serial=JH4127900705"])
def test_add_accepts_real_connections(uri):
    assert _code(helper.Helper(run=FakeRun()), {"op": "add", "name": "P", "uri": uri}) == "ok"


class FakeChpasswd:
    def __init__(self, ok=True):
        self.ok, self.calls = ok, []

    def __call__(self, user, password):
        self.calls.append((user, password))
        return self.ok


class AccountRun(FakeRun):
    """FakeRun plus the account commands cups-login uses."""

    def __init__(self, account_exists):
        super().__init__()
        self.account_exists = account_exists

    def __call__(self, args, timeout):
        if args[0] in ("id", "useradd", "usermod"):
            self.calls.append(args)
            return (0 if args[0] != "id" or self.account_exists else 1), "", ""
        return super().__call__(args, timeout)


def test_cups_login_creates_a_locked_down_account_and_returns_a_fresh_password():
    fake, chpasswd = AccountRun(account_exists=False), FakeChpasswd()

    result = helper.Helper(run=fake, chpasswd=chpasswd).handle({"op": "cups-login"})

    useradd = next(c for c in fake.calls if c[0] == "useradd")
    assert "/usr/sbin/nologin" in useradd and "lpadmin" in useradd and useradd[-1] == "cupsadmin"
    assert result["user"] == "cupsadmin" and len(result["password"]) >= 20
    assert chpasswd.calls == [("cupsadmin", result["password"])]
    assert not any(ch in result["password"] for ch in "@:/?# %")  # safe inside user:password@host


def test_cups_login_reuses_the_account_and_changes_the_password_every_time():
    fake, chpasswd = AccountRun(account_exists=True), FakeChpasswd()
    h = helper.Helper(run=fake, chpasswd=chpasswd)

    first, second = h.handle({"op": "cups-login"}), h.handle({"op": "cups-login"})

    assert not any(c[0] == "useradd" for c in fake.calls)
    assert first["password"] != second["password"]


def test_cups_login_reports_a_failed_password_change_without_returning_a_password():
    h = helper.Helper(run=AccountRun(account_exists=True), chpasswd=FakeChpasswd(ok=False))

    with pytest.raises(helper.Failure) as failure:
        h.handle({"op": "cups-login"})

    assert failure.value.code == "error"


def test_remove_deletes_only_an_existing_queue():
    fake = FakeRun(existing={"Zebra"})
    h = helper.Helper(run=fake)

    assert _code(h, {"op": "remove", "name": "Nope"}) == "not_found"
    assert _code(h, {"op": "remove", "name": "Zebra"}) == "ok"
    assert fake.calls[-1] == ["lpadmin", "-x", "Zebra"] and "Zebra" not in fake.existing


@pytest.mark.parametrize("request_body", [None, [], "discover", {}, {"op": "shell"}, {"op": "add"}])
def test_unknown_requests_are_refused(request_body):
    assert _code(helper.Helper(run=FakeRun()), request_body) in ("invalid_request", "invalid_name")


def test_helper_reads_a_request_only_if_it_is_a_small_regular_file_owned_by_the_service(tmp_path):
    uid = os.getuid()
    fd = os.open(tmp_path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        (tmp_path / "request").write_text(json.dumps({"op": "discover"}))
        assert helper.read_request(fd, uid) == {"op": "discover"}
        assert not (tmp_path / "request").exists()  # consumed

        (tmp_path / "request").write_text("x" * (helper.MAX_REQUEST_BYTES + 1))
        with pytest.raises(helper.Failure):
            helper.read_request(fd, uid)

        (tmp_path / "real").write_text("{}")
        os.symlink(tmp_path / "real", tmp_path / "request")
        with pytest.raises(helper.Failure):
            helper.read_request(fd, uid)

        os.mkfifo(tmp_path / "request2")
        os.rename(tmp_path / "request2", tmp_path / "request")
        with pytest.raises(helper.Failure):
            helper.read_request(fd, uid)

        (tmp_path / "request").write_text("not json")
        with pytest.raises(helper.Failure):
            helper.read_request(fd, uid)
    finally:
        os.close(fd)


def test_helper_refuses_a_request_not_owned_by_the_service_user(tmp_path):
    fd = os.open(tmp_path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        (tmp_path / "request").write_text("{}")
        with pytest.raises(helper.Failure):
            helper.read_request(fd, os.getuid() + 1)
    finally:
        os.close(fd)


def test_helper_result_replaces_a_planted_symlink_instead_of_following_it(tmp_path):
    target = tmp_path / "victim"
    target.write_text("keep")
    os.symlink(target, tmp_path / "result")
    fd = os.open(tmp_path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        helper.write_result(fd, {"code": "ok"})
    finally:
        os.close(fd)

    assert target.read_text() == "keep"
    assert json.loads((tmp_path / "result").read_text()) == {"code": "ok"}
    assert stat.S_ISREG((tmp_path / "result").lstat().st_mode)


# --- the backend client

def _client(tmp_path, answer):
    """A PrinterSetup whose "helper" is `answer(request) -> result dict | None`."""
    exchange = tmp_path / "exchange"
    requests = []

    async def start_unit():
        request = json.loads((exchange / "request").read_text())
        requests.append(request)
        result = answer(request)
        if result is not None:
            (exchange / "result").write_text(json.dumps(result))
        return 0, ""

    client = PrinterSetup(exchange_dir=exchange, start_unit=start_unit)
    client.sent = requests
    return client


def test_client_sends_the_request_and_cleans_up_the_exchange_directory(tmp_path):
    client = _client(tmp_path, lambda req: {"code": "ok"})

    run(client.add("Front_Office", "ipp://10.0.0.50/ipp/print"))

    assert client.sent == [{"op": "add", "name": "Front_Office", "uri": "ipp://10.0.0.50/ipp/print", "driver": "everywhere"}]
    assert not (tmp_path / "exchange" / "request").exists() and not (tmp_path / "exchange" / "result").exists()


@pytest.mark.parametrize("result, status", [
    ({"code": "invalid_name"}, 400), ({"code": "invalid_uri"}, 400), ({"code": "invalid_driver"}, 400), ({"code": "exists"}, 409),
    ({"code": "add_failed", "detail": "no driver"}, 422), ({"code": "error"}, 500), (None, 502)])
def test_client_maps_helper_answers_to_http_statuses(tmp_path, result, status):
    client = _client(tmp_path, lambda req: result)

    with pytest.raises(SetupError) as caught:
        run(client.add("P", "ipp://h/ipp/print"))

    assert caught.value.status == status
    if result and result.get("detail"):
        assert "no driver" in str(caught.value)


def test_client_never_reads_a_stale_result(tmp_path):
    client = _client(tmp_path, lambda req: None)
    (tmp_path / "exchange").mkdir()
    (tmp_path / "exchange" / "result").write_text(json.dumps({"code": "ok"}))

    with pytest.raises(SetupError) as caught:
        run(client.remove("Zebra"))

    assert caught.value.status == 502


def test_client_discover_curates_what_the_helper_found(tmp_path):
    devices = helper.parse_devices(LPINFO)
    client = _client(tmp_path, lambda req: {"code": "ok", "devices": devices})

    found = run(client.discover(existing_uris=set(), existing_names=set()))

    assert [(c.model, c.kind) for c in found] == [("Brother MFC-9970CDW", "network")]


# --- curating the device list

def _dev(uri, model="Brother MFC-9970CDW", klass="network", serial="", location=""):
    return {"uri": uri, "class": klass, "info": model, "make-and-model": model,
            "device-id": f"MFG:Brother;MDL:{model};" + (f"SN:{serial};" if serial else ""), "location": location}


def test_one_printer_found_by_several_protocols_is_listed_once_with_the_best_connection():
    found = curate(helper.parse_devices(LPINFO), set(), set())

    assert len(found) == 1
    printer = found[0]
    assert printer.uri.startswith("dnssd://") and printer.connection == "Network (IPP)" and printer.driverless
    assert printer.location == "Front office" and printer.name == "Brother_MFC-9970CDW"
    assert "socket://10.0.0.50" in printer.other_uris


def test_a_printer_that_only_advertises_raw_port_9100_is_listed_but_not_driverless():
    devices = helper.parse_devices(LPINFO_RAW_ONLY)

    (printer,) = curate(devices, set(), set())

    assert printer.model == "Brother MFC-9970CDW" and printer.uri.startswith("dnssd://Brother%20MFC-9970CDW._pdl-datastream")
    assert (printer.connection, printer.driverless) == ("Network (raw port 9100)", False)


def test_one_printers_several_bonjour_services_are_one_entry_and_the_ipp_one_wins():
    devices = [_dev("dnssd://Brother%20X._pdl-datastream._tcp.local/", serial="1"), _dev("dnssd://Brother%20X._printer._tcp.local/", serial="1"),
               _dev("dnssd://Brother%20X._ipp._tcp.local/", serial="1"), _dev("socket://10.0.0.50", serial="1")]

    (printer,) = curate(devices, set(), set())

    assert printer.uri == "dnssd://Brother%20X._ipp._tcp.local/" and printer.driverless
    assert len(printer.other_uris) == 3


def test_generic_placeholders_and_non_printer_devices_are_not_offered():
    devices = [{"uri": "file:/dev/null", "class": "file", "make-and-model": "Unknown"},
               {"uri": "ipp", "class": "network", "make-and-model": "Unknown"},
               {"uri": "serial:/dev/ttyS0", "class": "serial", "make-and-model": "Serial Port"},
               {"uri": "pipe:/x", "class": "network", "make-and-model": "Evil"}, "junk", None]

    assert curate(devices, set(), set()) == []


def test_two_identical_printers_with_different_serials_stay_two():
    devices = [_dev("dnssd://A._ipp._tcp.local/", serial="111"), _dev("socket://10.0.0.50", serial="111"),
               _dev("dnssd://B._ipp._tcp.local/", serial="222"), _dev("socket://10.0.0.51", serial="222")]

    found = curate(devices, set(), set())

    assert sorted(c.uri for c in found) == ["dnssd://A._ipp._tcp.local/", "dnssd://B._ipp._tcp.local/"]
    assert len({c.name for c in found}) == 2  # distinct suggested queue names


def test_identical_printers_without_serials_are_not_guessed_into_one():
    devices = [_dev("dnssd://A._ipp._tcp.local/"), _dev("dnssd://B._ipp._tcp.local/"), _dev("socket://10.0.0.50"),
               _dev("dnssd://A._pdl-datastream._tcp.local/")]  # a second *service* of printer A, not a third printer

    found = curate(devices, set(), set())

    assert sorted(c.uri for c in found) == ["dnssd://A._ipp._tcp.local/", "dnssd://B._ipp._tcp.local/"]


def test_raw_only_printers_are_one_entry_per_address_preferring_socket_over_lpd():
    devices = [_dev("lpd://10.0.0.50/queue"), _dev("socket://10.0.0.50:9100"), _dev("socket://10.0.0.51:9100")]

    found = curate(devices, set(), set())

    assert sorted(c.uri for c in found) == ["socket://10.0.0.50:9100", "socket://10.0.0.51:9100"]


def test_usb_printers_are_listed_on_their_own():
    devices = [_dev("usb://Zebra/ZD421?serial=1", model="Zebra ZD421", klass="direct")]

    (printer,) = curate(devices, set(), set())

    assert (printer.kind, printer.connection) == ("usb", "USB")


def test_a_printer_already_added_is_marked_and_sorted_last_whichever_connection_it_was_added_with():
    devices = helper.parse_devices(LPINFO) + [_dev("usb://Zebra/ZD421?serial=1", model="Zebra ZD421", klass="direct")]

    found = curate(devices, {"socket://10.0.0.50"}, {"Brother_MFC-9970CDW"})

    assert [(c.model, c.added) for c in found] == [("Zebra ZD421", False), ("Brother MFC-9970CDW", True)]
    assert found[1].name == "Brother_MFC-9970CDW_2"  # the name is taken, so a re-add gets a free one


def test_queue_names_are_always_valid_and_unique():
    assert queue_name("Zebra ZD421 (203 dpi)", set()) == "Zebra_ZD421_203_dpi"
    assert queue_name("Zebra", {"zebra"}) == "Zebra_2"
    assert queue_name("   ", set()) == "Printer"
    assert queue_name("ü ö", set()) == "Printer"
    assert helper.NAME_PATTERN.match(queue_name("-- weird / name!! --", set()))


@pytest.mark.parametrize("address, uri", [
    ("10.0.0.50", "ipp://10.0.0.50/ipp/print"), (" printer.local ", "ipp://printer.local/ipp/print"),
    ("10.0.0.50:631/ipp/lp", "ipp://10.0.0.50:631/ipp/lp"), ("socket://10.0.0.50:9100", "socket://10.0.0.50:9100")])
def test_a_typed_address_becomes_a_standard_driverless_uri(address, uri):
    assert address_to_uri(address) == uri


@pytest.mark.parametrize("address", ["", "   ", None, "bad host", "a;b", "$(x)"])
def test_nonsense_addresses_are_rejected(address):
    with pytest.raises(ValueError):
        address_to_uri(address)


# --- the endpoints

class FakeCupsForSetup:
    def __init__(self, printers=()):
        self._printers = printers

    async def printer_details(self):
        return list(self._printers)


class FakeSetup:
    def __init__(self, found=(), error=None):
        self.found, self.error, self.added, self.removed, self.discover_args = list(found), error, [], [], None

    async def discover(self, existing_uris, existing_names):
        self.discover_args = (existing_uris, existing_names)
        if self.error:
            raise self.error
        return self.found

    async def add(self, name, uri, driver="everywhere"):
        if self.error:
            raise self.error
        self.added.append((name, uri, driver))

    async def remove(self, name):
        if self.error:
            raise self.error
        self.removed.append(name)


def _endpoint(router, path, method):
    return next(r.endpoint for r in router.routes if r.path == path and method in r.methods)


def _router(setup, cups=None):
    return build_router(cups or FakeCupsForSetup(), printer_setup=setup)


def test_discover_endpoint_passes_existing_queues_and_returns_display_ready_printers():
    from .cups_client import PrinterDetails
    existing = PrinterDetails(name="Zebra", state="idle", message=None, model=None, device="usb://Zebra/ZD421", location=None,
                              accepting=True, jobs=0)
    printer = curate(helper.parse_devices(LPINFO), set(), set())
    setup = FakeSetup(found=printer)

    body = run(_endpoint(_router(setup, FakeCupsForSetup([existing])), "/api/local/printers/discover", "POST")())

    assert setup.discover_args == ({"usb://Zebra/ZD421"}, {"Zebra"})
    (p,) = body["printers"]
    assert p["display_uri"].startswith("dnssd://Brother MFC-9970CDW [3C2AF4]._ipp") and "%20" in p["uri"]
    assert p["added"] is False and p["connection"] == "Network (IPP)" and p["driverless"] is True


def test_discover_endpoint_reports_setup_errors_as_http_errors():
    with pytest.raises(HTTPException) as caught:
        run(_endpoint(_router(FakeSetup(error=SetupError(502, "no helper"))), "/api/local/printers/discover", "POST")())

    assert (caught.value.status_code, caught.value.detail) == (502, "no helper")


def test_add_endpoint_uses_the_discovered_uri_or_builds_one_from_a_typed_address():
    setup = FakeSetup()
    add = _endpoint(_router(setup), "/api/local/printers", "POST")

    run(add(PrinterAdd(name="A", uri="socket://10.0.0.50", driver="generic-postscript")))
    run(add(PrinterAdd(name="B", address="10.0.0.51")))

    assert setup.added == [("A", "socket://10.0.0.50", "generic-postscript"), ("B", "ipp://10.0.0.51/ipp/print", "everywhere")]


def test_add_endpoint_rejects_a_request_with_no_printer_or_a_nonsense_address():
    add = _endpoint(_router(FakeSetup()), "/api/local/printers", "POST")

    for body in (PrinterAdd(name="A"), PrinterAdd(name="A", address="bad host")):
        with pytest.raises(HTTPException) as caught:
            run(add(body))
        assert caught.value.status_code == 400


def test_add_and_remove_endpoints_surface_the_helpers_refusals():
    setup = FakeSetup(error=SetupError(422, "needs a driver"))

    with pytest.raises(HTTPException) as added:
        run(_endpoint(_router(setup), "/api/local/printers", "POST")(PrinterAdd(name="A", uri="socket://h")))
    with pytest.raises(HTTPException) as removed:
        run(_endpoint(_router(setup), "/api/local/printers/{name}", "DELETE")("A"))

    assert (added.value.status_code, added.value.detail) == (422, "needs a driver")
    assert removed.value.status_code == 422


def test_remove_endpoint_removes_by_name():
    setup = FakeSetup()

    run(_endpoint(_router(setup), "/api/local/printers/{name}", "DELETE")("Zebra"))

    assert setup.removed == ["Zebra"]
