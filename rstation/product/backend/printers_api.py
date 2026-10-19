"""Local API behind the on-device Settings > Printers page.

Deliberately small: it shows what CUPS has, prints a test page, finds / adds /
removes printers (printer_setup.py, through a root helper — this process holds no CUPS
credential; driverless, or a generic PostScript/PCL driver), and sets the password the
CUPS login uses (admin_password.py). Vendor drivers and media size stay in CUPS's own web
interface (rstation/PRINT_SERVER.md, "Printer setup on the device"). Like the rest of
/api/local, this only listens on loopback.
"""
import socket
from dataclasses import asdict
from datetime import datetime

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from . import ipp, testpage
from .admin_password import AdminPassword, PasswordError
from .cups_client import CupsClient, CupsError
from .printer_setup import PrinterSetup, SetupError, address_to_uri, display_uri


class PasswordChange(BaseModel):
    old: str | None = Field(default=None, max_length=1024)
    new: str = Field(max_length=1024)


class PrinterAdd(BaseModel):
    name: str = Field(max_length=63)
    # Either the connection CUPS discovered, or an address staff typed (a host or IP, or a full URI).
    uri: str | None = Field(default=None, max_length=512)
    address: str | None = Field(default=None, max_length=512)
    # "everywhere" (driverless, the default) or a generic driver; the root helper holds the allowed list.
    driver: str = Field(default="everywhere", max_length=32)


def build_router(cups: CupsClient, admin_password: AdminPassword | None = None,
                 printer_setup: PrinterSetup | None = None) -> APIRouter:
    router = APIRouter()
    admin_password = admin_password or AdminPassword()
    printer_setup = printer_setup or PrinterSetup()

    @router.get("/api/local/printers")
    async def printers():
        try:
            return {"printers": [asdict(p) for p in await cups.printer_details()], "error": None}
        except CupsError as exc:
            # Not an HTTP error: the page should say "the print system isn't running", not fail to load.
            return {"printers": [], "error": str(exc)}

    @router.post("/api/local/printers/{name}/test-page")
    async def test_page(name: str):
        try:
            known = {p.name for p in await cups.printer_details()}
            if name not in known:
                raise HTTPException(404, "That printer no longer exists.")
            pdf = testpage.build(name, socket.gethostname(), datetime.now())
            job_id = await cups.submit(name, pdf, "R Station test page", job_options={"print-scaling": ipp.keyword("auto")})
        except CupsError as exc:
            raise HTTPException(502, str(exc))
        return {"job_id": job_id}

    @router.post("/api/local/printers/discover")
    async def discover():
        """Printers CUPS can find on the network and USB. Takes several seconds (it listens for answers)."""
        try:
            existing = await cups.printer_details()
            found = await printer_setup.discover(
                existing_uris={p.device for p in existing if p.device}, existing_names={p.name for p in existing})
        except CupsError as exc:
            raise HTTPException(502, str(exc))
        except SetupError as exc:
            raise HTTPException(exc.status, str(exc))
        return {"printers": [{
            "name": c.name, "model": c.model, "uri": c.uri, "display_uri": display_uri(c.uri), "kind": c.kind,
            "connection": c.connection, "driverless": c.driverless, "location": c.location, "added": c.added} for c in found]}

    @router.post("/api/local/printers")
    async def add_printer(body: PrinterAdd):
        try:
            uri = address_to_uri(body.address) if body.address and not body.uri else body.uri
            if not uri:
                raise ValueError("Choose a printer or enter its address.")
        except ValueError as exc:
            raise HTTPException(400, str(exc))
        try:
            await printer_setup.add(body.name, uri, body.driver)
        except SetupError as exc:
            raise HTTPException(exc.status, str(exc))
        return {"name": body.name}

    @router.delete("/api/local/printers/{name}")
    async def remove_printer(name: str):
        try:
            await printer_setup.remove(name)
        except SetupError as exc:
            raise HTTPException(exc.status, str(exc))
        return {"name": name}

    @router.get("/api/local/printers/admin-password")
    def admin_password_status():
        return {"set": admin_password.is_set()}

    @router.post("/api/local/printers/admin-password")
    async def admin_password_change(body: PasswordChange):
        try:
            await admin_password.change(body.old, body.new)
        except PasswordError as exc:
            raise HTTPException(exc.status, str(exc))
        return {"set": True}

    return router
