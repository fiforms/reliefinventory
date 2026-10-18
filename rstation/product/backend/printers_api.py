"""Local API behind the on-device Settings > Printers page.

Deliberately small: it shows what CUPS has, prints a test page, and sets the
password the CUPS login uses (admin_password.py). Adding a
printer, picking its driver and media size stay in CUPS's own web interface
(rstation/PRINT_SERVER.md, "Printer setup on the device") — those need a CUPS
admin login and driver choices that aren't worth rebuilding. Like the rest of
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


class PasswordChange(BaseModel):
    old: str | None = Field(default=None, max_length=1024)
    new: str = Field(max_length=1024)


def build_router(cups: CupsClient, admin_password: AdminPassword | None = None) -> APIRouter:
    router = APIRouter()
    admin_password = admin_password or AdminPassword()

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
