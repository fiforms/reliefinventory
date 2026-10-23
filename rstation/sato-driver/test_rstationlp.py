import importlib
import importlib.machinery
import importlib.util
import io
import os
import subprocess
import sys
from pathlib import Path

import pytest

HERE = Path(__file__).parent
BACKEND = HERE / "rstationlp"


def load(sysfs, devdir):
    """Imports the backend with its sysfs / device directory pointed at a fake tree (they are read at import)."""
    os.environ["RSTATIONLP_SYSFS"] = str(sysfs)
    os.environ["RSTATIONLP_DEVDIR"] = str(devdir)
    loader = importlib.machinery.SourceFileLoader("rstationlp", str(BACKEND))
    spec = importlib.util.spec_from_loader("rstationlp", loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


def add_printer(sysfs, name, vendor="0828", product="a003", serial="JH4127900705"):
    """A fake /sys with the layout the kernel uses: class/usbmisc/<lp> -> .../<usb dev>/<usb dev>:1.0/usbmisc/<lp>."""
    usb_device = sysfs / "devices/pci/usb1" / f"1-1.{name[-1]}"
    iface = usb_device / f"1-1.{name[-1]}:1.0"
    (iface / "usbmisc" / name).mkdir(parents=True)
    for file, value in (("idVendor", vendor), ("idProduct", product), ("serial", serial)):
        (usb_device / file).write_text(value + "\n")
    link = sysfs / "class/usbmisc" / name
    link.parent.mkdir(parents=True, exist_ok=True)
    link.symlink_to(iface / "usbmisc" / name)
    (iface / "usbmisc" / name / "device").symlink_to(iface)


@pytest.fixture
def tree(tmp_path):
    sysfs, dev = tmp_path / "sys", tmp_path / "dev"
    dev.mkdir()
    return sysfs, dev


def test_discovery_lists_the_ws408_by_serial_and_ignores_other_usb_printers(tree, capsys):
    sysfs, dev = tree
    add_printer(sysfs, "lp0")
    add_printer(sysfs, "lp1", vendor="04f9", product="0042", serial="OTHER")  # a Brother: the stock backend's business
    backend = load(sysfs, dev)
    assert backend.main(["rstationlp"]) == backend.OK
    out = capsys.readouterr().out.strip().splitlines()
    assert len(out) == 1
    assert out[0].startswith('direct rstationlp://SATO/WS408?serial=JH4127900705 "SATO WS408" ')


def test_discovery_prints_nothing_when_no_printer_is_attached(tree, capsys):
    sysfs, dev = tree
    (sysfs / "class/usbmisc").mkdir(parents=True)
    backend = load(sysfs, dev)
    assert backend.main(["rstationlp"]) == backend.OK
    assert capsys.readouterr().out == ""


def test_uri_picks_the_node_of_the_printer_with_that_serial(tree):
    sysfs, dev = tree
    add_printer(sysfs, "lp0", serial="AAA")
    add_printer(sysfs, "lp1", serial="BBB")
    backend = load(sysfs, dev)
    assert backend.node_for_uri("rstationlp://SATO/WS408?serial=BBB") == str(dev / "lp1")
    assert backend.node_for_uri("rstationlp://SATO/WS408?serial=AAA") == str(dev / "lp0")
    assert backend.node_for_uri("rstationlp://SATO/WS408?serial=CCC") is None
    assert backend.node_for_uri("rstationlp://SATO/WS408") is None  # two printers and no serial: ambiguous
    assert backend.node_for_uri("usb://SATO/WS408?serial=AAA") is None


def test_uri_without_a_serial_works_when_only_one_printer_is_attached(tree):
    sysfs, dev = tree
    add_printer(sysfs, "lp0", serial="")
    backend = load(sysfs, dev)
    assert backend.device_uri(backend.attached_printers()[0]) == "rstationlp://SATO/WS408"
    assert backend.node_for_uri("rstationlp://SATO/WS408") == str(dev / "lp0")


def test_job_bytes_are_written_to_the_node_unchanged(tree):
    sysfs, dev = tree
    add_printer(sysfs, "lp0")
    (dev / "lp0").write_bytes(b"")
    backend = load(sysfs, dev)
    payload = bytes(range(256)) * 1000  # bigger than one chunk, with every byte value
    assert backend.send("rstationlp://SATO/WS408?serial=JH4127900705", io.BytesIO(payload)) == backend.OK
    assert (dev / "lp0").read_bytes() == payload


def test_a_missing_printer_asks_cups_to_retry_the_job(tree, monkeypatch):
    sysfs, dev = tree
    (sysfs / "class/usbmisc").mkdir(parents=True)
    backend = load(sysfs, dev)
    monkeypatch.setattr(backend, "OPEN_WAIT_SECONDS", 0)
    assert backend.send("rstationlp://SATO/WS408?serial=X", io.BytesIO(b"data")) == backend.RETRY_CURRENT


def test_run_as_cups_runs_it_with_a_job_file_and_the_device_uri(tree, tmp_path):
    sysfs, dev = tree
    add_printer(sysfs, "lp0")
    (dev / "lp0").write_bytes(b"")
    job = tmp_path / "job.sbpl"
    job.write_bytes(b"\x1bA\x1bZ")
    env = {**os.environ, "RSTATIONLP_SYSFS": str(sysfs), "RSTATIONLP_DEVDIR": str(dev),
           "DEVICE_URI": "rstationlp://SATO/WS408?serial=JH4127900705"}
    done = subprocess.run([sys.executable, "-I", str(BACKEND), "1", "user", "title", "1", "", str(job)],
                          env=env, capture_output=True)
    assert done.returncode == 0, done.stderr
    assert (dev / "lp0").read_bytes() == b"\x1bA\x1bZ"
    bad = subprocess.run([sys.executable, "-I", str(BACKEND), "1", "user", "title", "1", "", str(job)],
                         env={**env, "DEVICE_URI": "usb://SATO/WS408"}, capture_output=True)
    assert bad.returncode == 1


def test_the_stock_backend_is_told_to_ignore_every_printer_this_one_handles():
    quirks = (HERE / "rstation-sato.usb-quirks").read_text().split("\n")
    lines = {tuple(line.split()[:3]) for line in quirks if line.strip() and not line.startswith("#")}
    backend = load(Path("/nonexistent"), Path("/nonexistent"))
    for vendor, product in backend.PRINTERS:
        assert (f"0x{vendor}", f"0x{product}", "blacklist") in lines
