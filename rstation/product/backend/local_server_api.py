"""Local API behind the on-device Settings > Local server page (see local_server.py).

Like the rest of /api/local, this only listens on loopback.
"""
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

from .local_server import LocalServer, LocalServerError


class Home(BaseModel):
    enabled: bool


class Reset(BaseModel):
    # The page asks "erase everything?" first; this makes a bare POST (a stray script, a retry) do nothing.
    confirm: bool = False


def build_router(server: LocalServer | None = None) -> APIRouter:
    router = APIRouter()
    server = server or LocalServer()

    async def act(action: str):
        try:
            await server.act(action)
        except LocalServerError as exc:
            raise HTTPException(exc.status, str(exc))
        return await server.status()

    @router.get("/api/local/local-server")
    async def status():
        return await server.status()

    @router.post("/api/local/local-server/enable")
    async def enable():
        return await act("enable")

    @router.post("/api/local/local-server/disable")
    async def disable():
        return await act("disable")

    @router.post("/api/local/local-server/home")
    async def home(body: Home):
        try:
            server.set_home(body.enabled)
        except LocalServerError as exc:
            raise HTTPException(exc.status, str(exc))
        return await server.status()

    @router.post("/api/local/local-server/reset")
    async def reset(body: Reset):
        if not body.confirm:
            raise HTTPException(400, "Confirm that all the server's data should be erased.")
        return await act("reset")

    return router
