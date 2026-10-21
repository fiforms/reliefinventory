import asyncio
import json

import httpx

from .browser_session_api import build_router
from .print_api import PrintApi

run = asyncio.run


def _ticket_endpoint(handler, token="tok"):
    api = PrintApi(url=lambda path: f"https://ri.example.org/api/devices/{path}", token=lambda: token,
                   transport=httpx.MockTransport(handler))
    router = build_router(api)
    return next(r.endpoint for r in router.routes if r.path == "/api/local/browser-ticket")


def test_a_paired_device_gets_the_servers_ticket():
    seen = {}

    def handler(request):
        seen.update(url=str(request.url), auth=request.headers["Authorization"], body=json.loads(request.content))
        return httpx.Response(200, json={"ticket": "abc123", "expires_in": 60})

    assert run(_ticket_endpoint(handler)()) == {"ticket": "abc123"}
    assert seen == {"url": "https://ri.example.org/api/devices/session-ticket", "auth": "Bearer tok", "body": {}}


def test_unpaired_means_no_ticket_and_no_request():
    def handler(request):
        raise AssertionError("must not call the server without a token")

    assert run(_ticket_endpoint(handler, token=None)()) == {"ticket": None}


def test_revoked_unreachable_and_garbled_answers_all_mean_no_ticket():
    assert run(_ticket_endpoint(lambda r: httpx.Response(401, json={"message": "Unauthenticated."}))()) == {"ticket": None}
    assert run(_ticket_endpoint(lambda r: httpx.Response(503))()) == {"ticket": None}
    assert run(_ticket_endpoint(lambda r: httpx.Response(200, json={"nope": 1}))()) == {"ticket": None}

    def down(request):
        raise httpx.ConnectError("unreachable")

    assert run(_ticket_endpoint(down)()) == {"ticket": None}
