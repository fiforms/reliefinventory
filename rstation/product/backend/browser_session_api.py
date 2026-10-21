"""Local API behind the launcher's hand-off to the website (frontend/Main.vue).

The browser must never hold this device's bearer token, so the launcher asks *this backend* for a
single-use ticket and sends the browser to /device-session/{ticket}; the server turns that into a
cookie that opens only the pages for the device's configured Function — no user login. Anything that
goes wrong answers {"ticket": null} and the launcher falls back to the ordinary sign-in page, so a
screen is never stranded. Like the rest of /api/local, this only listens on loopback.
"""
import logging

from fastapi import APIRouter

from .print_api import ApiError, PrintApi, Revoked

log = logging.getLogger("browser_session")


def build_router(api: PrintApi) -> APIRouter:
    router = APIRouter()

    @router.post("/api/local/browser-ticket")
    async def browser_ticket():
        try:
            return {"ticket": await api.session_ticket()}
        except Revoked:
            # Unpaired or revoked: the heartbeat's own 401 handling does the wipe; just don't hand out a ticket.
            return {"ticket": None}
        except (ApiError, KeyError, ValueError) as exc:  # includes Transient (server unreachable)
            log.info("no browser ticket: %s", exc)
            return {"ticket": None}

    return router
