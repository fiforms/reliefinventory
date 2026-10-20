"""R Station product layer: a remote portal on the kiosk screen, plus a print
server for the printers attached to this device.

Shows each backend seam in a few lines — a router, a status field and data
riding on the heartbeat. Copy this directory as a starting point; see
docs/PRODUCTS.md.

The print server (print_station.py) claims print jobs from the website and
prints them on this device's CUPS queues. It is a background task here, its
printer list rides on every heartbeat, and its state lives under /data and is
wiped with the pairing. Design: rstation/PRINT_SERVER.md and
rstation/PRINT_CLIENT_SPEC.md.

The local server (local_server.py) runs a whole offline copy of the website on this device
for sites with no Internet; Settings > Local server controls it. Design: rstation/LOCAL_SERVER.md."""
from fastapi import APIRouter

from product import Product

from .local_server_api import build_router as local_server_router
from .print_station import default_station
from .printers_api import build_router as printers_router

router = APIRouter()
station = default_station()


@router.get("/api/local/portal/ping")
def ping():
    return {"ok": True}


product = Product(
    name="rstation",
    # Where this product's server implements docs/DEVICE_CONTRACT.md.
    api_base="/api/devices",
    # The first-run wizard confirms which server this device belongs to (the
    # frontend's `setupSteps` supplies the step); the boot yaml's server_url is
    # only its default. See platform/docs/SERVER_URL.md.
    confirm_server_url=True,
    routers=[router, printers_router(station.cups), local_server_router()],
    # Claims print jobs from the website and prints them (see print_station.py).
    background_tasks=[station.run],
    # Appears as `portal` and `print_station` in GET /api/local/status.
    status_fields=lambda: {"portal": {"mode": "remote"}, **station.status()},
    # Merged into every heartbeat; namespaced so it can't collide. The print
    # keys (print_platform, print_printers) are empty until a queue exists.
    heartbeat_payload=lambda: {"portal_note": "hello from the example product", **station.heartbeat_payload()},
    # The heartbeat response carries the print server's realtime wake-up channel.
    on_heartbeat_response=station.on_heartbeat_response,
    # A re-pair may attach this device to different print jobs; forget what was done for the old ones.
    wipe_paths=[station.ledger.directory],
)
