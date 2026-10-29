<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [

    /*
    |--------------------------------------------------------------------------
    | Slow query log (app side)
    |--------------------------------------------------------------------------
    |
    | Every database query that takes at least this many milliseconds is
    | appended to storage/logs/slow-queries.jsonl together with the request
    | (or artisan command) that ran it — which tells you which screen is slow,
    | not just which SQL. 0 turns it off. Bindings are never written, so
    | passwords, emails and the like can't land in the file.
    |
    | The Docker stack turns this on (250 ms) and also switches on MariaDB's own
    | slow log; see docker/compose.yaml and docker/README.md. View it at
    | Setup > Testing Tools or with `php artisan slowlog:show`.
    |
    */

    'threshold_ms' => (int) env('RI_SLOW_QUERY_MS', 0),

    // Whole-request profiling (only while threshold_ms > 0): a request is written to the log when it takes at
    // least request_ms, runs at least request_queries queries, or sends at least request_bytes — slow SCREENS
    // that no single slow query explains (a list loading thousands of rows, a multi-megabyte response).
    'request_ms' => (int) env('RI_SLOW_REQUEST_MS', 1000),
    'request_queries' => (int) env('RI_CHATTY_REQUEST_QUERIES', 60),
    'request_bytes' => (int) env('RI_HEAVY_RESPONSE_BYTES', 1024 * 1024),

    'path' => storage_path('logs/slow-queries.jsonl'),

    // When the file passes this size it is rotated to slow-queries.jsonl.1 (one old copy kept).
    'max_bytes' => 5 * 1024 * 1024,
];
