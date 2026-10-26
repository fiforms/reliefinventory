<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [
    // Minutes a trust pairing code / invitation stays valid.
    'pairing_code_minutes' => 15,

    // Signed requests: how far a request timestamp may differ from this server's clock, and how long a
    // nonce is remembered to refuse replays (must be at least twice the skew).
    'max_skew_seconds' => 300,
    'nonce_ttl_seconds' => 600,

    // Session token handed out after one signed call, so a client need not sign every request.
    'session_ttl_seconds' => 3600,

    // The pre-federation shared-secret feedback ingest (POST /json/feedback/ingest) stays on for one release so
    // a remote that has not been moved to a trust keeps reporting. Set false once every remote has moved.
    'legacy_feedback_secret' => (bool) env('RI_FEDERATION_LEGACY_FEEDBACK', true),

    // Devices paired before federation hold a bearer token. With this on, such a token is still accepted by
    // `device-auth` so a fleet can be updated gradually; new pairing is always by key. Remove once every device has
    // re-paired.
    'legacy_device_tokens' => (bool) env('RI_FEDERATION_LEGACY_DEVICES', false),

    // Outbound calls to another instance's address. https only and no private/loopback hosts, unless an
    // operator turns these on for a LAN or development setup (two local instances).
    'allow_insecure_http' => (bool) env('RI_FEDERATION_ALLOW_HTTP', false),
    'allow_private_hosts' => (bool) env('RI_FEDERATION_ALLOW_PRIVATE', false),
];
