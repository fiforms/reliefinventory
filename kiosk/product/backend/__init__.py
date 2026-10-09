"""Minimal example product: a kiosk that opens a remote portal.

Shows each backend seam in a few lines — a router, a status field and data
riding on the heartbeat. Copy this directory as a starting point; see
docs/PRODUCTS.md."""
from fastapi import APIRouter

from product import Product

router = APIRouter()


@router.get("/api/local/portal/ping")
def ping():
    return {"ok": True}


product = Product(
    name="portal",
    # Where this product's server implements docs/DEVICE_CONTRACT.md.
    api_base="/api/devices",
    routers=[router],
    # Appears as `portal` in GET /api/local/status.
    status_fields=lambda: {"portal": {"mode": "remote"}},
    # Merged into every heartbeat; namespaced so it can't collide.
    heartbeat_payload=lambda: {"portal_note": "hello from the example product"},
)
