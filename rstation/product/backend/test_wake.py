import json
import threading

from . import wake
from .wake import WakeListener, handle_message, socket_url

REALTIME = {"driver": "reverb", "key": "app-key", "host": "ri.example.org", "port": 443, "scheme": "https",
            "path": "", "channel": "wake.abc123", "event": "print-job.available"}


def test_socket_url_follows_the_documented_pusher_endpoint():
    assert socket_url(REALTIME) == "wss://ri.example.org:443/app/app-key?protocol=7&client=ri-print-station&version=1.0"
    assert socket_url({**REALTIME, "scheme": "http", "port": 8080}).startswith("ws://ri.example.org:8080/app/app-key")
    assert "/proxy/app/app-key" in socket_url({**REALTIME, "path": "/proxy/"})  # a path prefix, tolerant of a trailing slash


def _message(event, channel=None, data=None):
    body = {"event": event}
    if channel:
        body["channel"] = channel
    if data is not None:
        body["data"] = data
    return json.dumps(body)


def test_connecting_subscribes_to_this_devices_channel():
    (action,) = handle_message(_message("pusher:connection_established", data='{"socket_id":"1.2"}'), "wake.abc123", "print-job.available")

    assert action[0] == "send"
    assert json.loads(action[1]) == {"event": "pusher:subscribe", "data": {"channel": "wake.abc123"}}


def test_subscription_success_means_connected():
    assert handle_message(_message("pusher_internal:subscription_succeeded", "wake.abc123", "{}"), "wake.abc123", "e") == [("connected", True)]
    # ...but only for our own channel
    assert handle_message(_message("pusher_internal:subscription_succeeded", "other", "{}"), "wake.abc123", "e") == []


def test_server_pings_are_answered():
    (action,) = handle_message(_message("pusher:ping"), "wake.abc123", "e")

    assert action[0] == "send" and json.loads(action[1])["event"] == "pusher:pong"


def test_the_wake_event_on_our_channel_means_poll_now():
    assert handle_message(_message("print-job.available", "wake.abc123", "[]"), "wake.abc123", "print-job.available") == [("wake",)]


def test_anything_else_is_ignored():
    for raw in (
        _message("print-job.available", "someone-elses-channel", "[]"),  # right event, wrong channel
        _message("some.other.event", "wake.abc123", "[]"),
        _message("pusher:error", data={"code": 4001}),
        "not json at all",
        "",
    ):
        assert handle_message(raw, "wake.abc123", "print-job.available") == []


class _CountingThread:
    started: list = []

    def __init__(self, target, args, name, daemon):
        self.args = args

    def start(self):
        _CountingThread.started.append(self.args)


def test_configure_starts_once_per_distinct_config_and_stops_on_none(monkeypatch):
    _CountingThread.started = []
    monkeypatch.setattr(threading, "Thread", _CountingThread)
    listener = WakeListener(on_wake=lambda: None)

    listener.configure(REALTIME)
    listener.configure(dict(REALTIME))  # identical details: heartbeats repeat this every 45 s, must not reconnect
    assert len(_CountingThread.started) == 1

    listener.configure({**REALTIME, "channel": "wake.rotated"})  # the channel rotated (re-pair): reconnect
    assert len(_CountingThread.started) == 2

    listener.configure(None)  # realtime turned off
    listener.configure(None)
    assert len(_CountingThread.started) == 2 and listener.connected is False
    assert listener._current(_CountingThread.started[-1][1]) is False  # the old thread has been told to stop


def test_incomplete_details_are_treated_as_off(monkeypatch):
    _CountingThread.started = []
    monkeypatch.setattr(threading, "Thread", _CountingThread)
    listener = WakeListener(on_wake=lambda: None)

    for broken in ({"host": "h"}, {**REALTIME, "channel": ""}, {**REALTIME, "key": None}, {}, None):
        listener.configure(broken)

    assert _CountingThread.started == []


def test_an_unreachable_server_just_means_not_connected_and_stops_cleanly():
    unreachable = {**REALTIME, "scheme": "http", "host": "127.0.0.1", "port": 1}  # nothing listens on port 1
    listener = WakeListener(on_wake=lambda: None)

    listener.configure(unreachable)
    threading.Event().wait(0.5)  # give the thread a moment to try and fail
    assert listener.connected is False

    listener.stop()
    wake_threads = [t for t in threading.enumerate() if t.name == "print-wake"]
    for thread in wake_threads:
        thread.join(timeout=5)
    assert not any(t.is_alive() for t in wake_threads)
