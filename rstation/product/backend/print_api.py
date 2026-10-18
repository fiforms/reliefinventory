"""The print server's side of the website's print-job API — the endpoints in
rstation/PRINT_SERVER.md "Wire protocol" / rstation/print-extension.openapi.yaml,
plus the heartbeat (which this daemon also sends on its own, much more often
than the platform's 5-minute one, so a printer fault reaches the Print menu in
seconds).

Every non-2xx answer becomes one exception type, so the caller's decisions
read straight off PRINT_CLIENT_SPEC.md section 3.6:

    Revoked      401  the device was un-paired: wipe and return to setup
    NotFound     404  not this device's job, or gone: drop it
    Conflict     409  claim lost / not claimable / result not accepted: drop it
    Gone         410  the PDF was cleaned up: report the job failed
    RateLimited  429  back off
    Transient    network errors and 5xx: retry later
"""
from typing import Awaitable, Callable

import httpx


class ApiError(Exception):
    def __init__(self, message: str, status: int | None = None):
        super().__init__(message)
        self.status = status


class Revoked(ApiError):
    pass


class NotFound(ApiError):
    pass


class Conflict(ApiError):
    pass


class Gone(ApiError):
    pass


class RateLimited(ApiError):
    pass


class Transient(ApiError):
    pass


def _default_url(path: str) -> str:
    import pairing  # the platform's pairing module; imported late so tests can run without it

    return pairing.api_url(path)


def _default_token() -> str | None:
    import pairing

    return pairing.read_device_token()


class PrintApi:
    def __init__(self, url: Callable[[str], str] = _default_url, token: Callable[[], str | None] = _default_token,
                 transport: httpx.AsyncBaseTransport | None = None):
        self._url = url
        self._token = token
        self._transport = transport

    async def _request(self, method: str, path: str, *, json: dict | None = None, timeout: float = 10) -> httpx.Response:
        token = self._token()
        if not token:
            raise Revoked("This device is not paired.")
        try:
            async with httpx.AsyncClient(timeout=timeout, transport=self._transport) as client:
                response = await client.request(method, self._url(path), json=json, headers={"Authorization": f"Bearer {token}"})
        except httpx.RequestError as exc:
            raise Transient(f"Could not reach the server: {exc}") from exc

        status = response.status_code
        if status < 300:
            return response
        message = f"Server answered HTTP {status}"
        try:
            message = response.json().get("message") or message
        except ValueError:
            pass
        if status == 401:
            raise Revoked(message, status)
        if status == 404:
            raise NotFound(message, status)
        if status == 409:
            raise Conflict(message, status)
        if status == 410:
            raise Gone(message, status)
        if status == 429:
            raise RateLimited(message, status)
        if status >= 500:
            raise Transient(message, status)
        raise ApiError(message, status)

    async def heartbeat(self, payload: dict) -> dict:
        return (await self._request("POST", "heartbeat", json=payload)).json()

    async def jobs(self) -> list[dict]:
        return (await self._request("GET", "print-jobs")).json().get("jobs", [])

    async def claim(self, job_id: int) -> dict:
        """Claims a job — or, for a job this device already holds, keeps the
        claim alive (the server extends it; no new attempt)."""
        return (await self._request("POST", f"print-jobs/{job_id}/claim")).json()

    async def payload(self, job_id: int) -> bytes:
        return (await self._request("GET", f"print-jobs/{job_id}/payload", timeout=60)).content

    async def result(self, job_id: int, status: str, error: str | None = None) -> dict:
        body = {"status": status}
        if error:
            body["error"] = error[:2000]
        return (await self._request("POST", f"print-jobs/{job_id}/result", json=body)).json()
