"""Optional instant wake-ups from the website's realtime server (Laravel
Reverb) — rstation/PRINT_SERVER.md "Realtime wake-ups".

The heartbeat response carries `print_realtime` (host, key, channel, ...) when
realtime is on. This opens a Pusher-protocol WebSocket to it, subscribes to this
device's own public channel, and calls `on_wake` whenever `print-job.available`
arrives. That message means only "poll now": claiming and downloading still use
the bearer token over HTTPS, so the channel name is the sole secret and this
needs no signing handshake.

It is purely an accelerator. The caller keeps polling (fast while this is
disconnected, slow while it's connected), so every failure here — server down,
blocked port, malformed message — just means "not connected".

Runs on a daemon thread because the platform ships `websocket-client`
(synchronous), not an asyncio WebSocket library.
"""
import json
import logging
import threading
from typing import Callable

log = logging.getLogger("print_station.wake")

CLIENT = "ri-print-station"


def socket_url(realtime: dict) -> str:
    """`wss://host:443/app/<key>` from the heartbeat's print_realtime object."""
    scheme = "wss" if realtime.get("scheme") == "https" else "ws"
    path = (realtime.get("path") or "").rstrip("/")
    return f"{scheme}://{realtime['host']}:{realtime['port']}{path}/app/{realtime['key']}?protocol=7&client={CLIENT}&version=1.0"


def handle_message(raw: str, channel: str, event: str) -> list[tuple]:
    """Pure protocol logic: one incoming Pusher message -> what to do about it.

    Returns a list of actions: ("send", text), ("connected", bool) or ("wake",).
    """
    try:
        message = json.loads(raw)
    except ValueError:
        return []
    name = message.get("event")
    if name == "pusher:connection_established":
        return [("send", json.dumps({"event": "pusher:subscribe", "data": {"channel": channel}}))]
    if name == "pusher_internal:subscription_succeeded" and message.get("channel") == channel:
        return [("connected", True)]
    if name == "pusher:ping":
        return [("send", json.dumps({"event": "pusher:pong", "data": {}}))]
    if name == event and message.get("channel") == channel:
        return [("wake",)]
    if name == "pusher:error":
        log.warning("Realtime server reported an error: %s", message.get("data"))
    return []


class WakeListener:
    def __init__(self, on_wake: Callable[[], None]):
        self._on_wake = on_wake
        self._lock = threading.Lock()
        self._config: dict | None = None
        self._generation = 0
        self._app = None
        self.connected = False

    def configure(self, realtime: dict | None) -> None:
        """Called with every heartbeat response's print_realtime. Cheap and
        non-blocking; (re)starts the listener only when the details change, and
        stops it when realtime has been turned off (or the device revoked)."""
        usable = realtime if realtime and all(realtime.get(k) for k in ("host", "port", "key", "channel")) else None
        with self._lock:
            if usable == self._config:
                return
            self._config = usable
            self._generation += 1
            generation = self._generation
            self.connected = False
            if self._app is not None:
                try:
                    self._app.close()
                except Exception:  # noqa: BLE001 - closing a dead socket must never raise into the heartbeat
                    pass
                self._app = None
        if usable:
            threading.Thread(target=self._run, args=(usable, generation), name="print-wake", daemon=True).start()

    def stop(self) -> None:
        self.configure(None)

    def _current(self, generation: int) -> bool:
        with self._lock:
            return generation == self._generation

    def _run(self, config: dict, generation: int) -> None:
        import time

        import websocket  # websocket-client, from the platform's requirements

        backoff = 1.0
        while self._current(generation):
            channel, event = config["channel"], config.get("event") or "print-job.available"

            def on_message(app, raw, channel=channel, event=event):
                for action in handle_message(raw, channel, event):
                    if action[0] == "send":
                        app.send(action[1])
                    elif action[0] == "connected" and self._current(generation):
                        self.connected = True
                    elif action[0] == "wake" and self._current(generation):
                        self._on_wake()

            app = websocket.WebSocketApp(socket_url(config), on_message=on_message)
            with self._lock:
                if generation != self._generation:
                    return
                self._app = app
            started = time.monotonic()
            try:
                app.run_forever(ping_interval=30, ping_timeout=10)
            except Exception as exc:  # noqa: BLE001 - any failure is just "not connected"
                log.info("Realtime connection error: %s", exc)
            if self._current(generation):
                self.connected = False
            # A connection that lived a while resets the backoff; quick failures back off up to a minute.
            backoff = 1.0 if time.monotonic() - started > 60 else min(backoff * 2, 60.0)
            deadline = time.monotonic() + backoff
            while self._current(generation) and time.monotonic() < deadline:
                time.sleep(0.5)
