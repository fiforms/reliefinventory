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
];
