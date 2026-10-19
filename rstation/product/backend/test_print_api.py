import asyncio
import json

import httpx
import pytest

from .print_api import ApiError, Conflict, Gone, NotFound, PrintApi, RateLimited, Revoked, Transient

run = asyncio.run


def _api(handler, token="tok-123"):
    transport = httpx.MockTransport(handler)
    return PrintApi(url=lambda path: f"https://ri.example.org/api/devices/{path}", token=lambda: token, transport=transport)


def test_every_call_is_bearer_authenticated_against_the_device_api():
    seen = []

    def handler(request):
        seen.append((request.method, str(request.url), request.headers["authorization"]))
        return httpx.Response(200, json={"jobs": []})

    run(_api(handler).jobs())

    assert seen == [("GET", "https://ri.example.org/api/devices/print-jobs", "Bearer tok-123")]


def test_an_unpaired_device_never_calls_the_server():
    called = []
    api = _api(lambda request: called.append(request) or httpx.Response(200, json={}), token=None)

    with pytest.raises(Revoked):
        run(api.jobs())

    assert called == []


@pytest.mark.parametrize("status, expected", [
    (401, Revoked), (404, NotFound), (409, Conflict), (410, Gone), (429, RateLimited),
    (500, Transient), (503, Transient), (422, ApiError), (400, ApiError),
])
def test_http_failures_map_to_one_exception_each_with_the_servers_message(status, expected):
    api = _api(lambda request: httpx.Response(status, json={"message": "the reason"}))

    with pytest.raises(expected, match="the reason") as raised:
        run(api.claim(5))

    assert type(raised.value) is expected and raised.value.status == status


def test_a_non_json_error_body_still_raises_the_right_exception():
    api = _api(lambda request: httpx.Response(502, text="<html>bad gateway</html>"))

    with pytest.raises(Transient, match="HTTP 502"):
        run(api.jobs())


def test_network_errors_are_transient():
    def handler(request):
        raise httpx.ConnectTimeout("timed out")

    with pytest.raises(Transient, match="Could not reach the server"):
        run(_api(handler).heartbeat({"print_printers": []}))


def test_calls_use_the_documented_endpoints_and_bodies():
    seen = []

    def handler(request):
        seen.append((request.method, request.url.path, request.content))
        if request.url.path.endswith("/payload"):
            return httpx.Response(200, content=b"%PDF-1.4 bytes", headers={"content-type": "application/pdf"})
        return httpx.Response(200, json={"jobs": [{"id": 4}], "id": 4, "status": "printed", "ok": True})

    api = _api(handler)
    assert run(api.jobs()) == [{"id": 4}]
    run(api.claim(4))
    assert run(api.payload(4)) == b"%PDF-1.4 bytes"
    run(api.result(4, "failed", "Out of labels"))
    run(api.result(4, "printed"))
    run(api.heartbeat({"print_platform": "rstation"}))

    paths = [(method, path) for method, path, _ in seen]
    assert paths == [
        ("GET", "/api/devices/print-jobs"), ("POST", "/api/devices/print-jobs/4/claim"),
        ("GET", "/api/devices/print-jobs/4/payload"), ("POST", "/api/devices/print-jobs/4/result"),
        ("POST", "/api/devices/print-jobs/4/result"), ("POST", "/api/devices/heartbeat"),
    ]
    assert json.loads(seen[3][2]) == {"status": "failed", "error": "Out of labels"}
    assert json.loads(seen[4][2]) == {"status": "printed"}  # no error key when there isn't one


def test_a_long_error_is_cut_to_the_servers_limit():
    seen = []
    api = _api(lambda request: seen.append(json.loads(request.content)) or httpx.Response(200, json={"id": 1, "status": "failed"}))

    run(api.result(1, "failed", "x" * 5000))

    assert len(seen[0]["error"]) == 2000
