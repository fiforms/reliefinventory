<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [
    // Print media ("what size/kind of paper a printer is loaded with"). A
    // printer and a print job each carry one of these keys as a plain string —
    // deliberately not a DB enum, so user-defined sizes later means moving this
    // list into a table without touching printers/jobs/the device API.
    // Dimensions are inches (width x height).
    'media' => [
        'report' => ['label' => 'Report (Letter)', 'width' => 8.5, 'height' => 11.0],
        'label_large' => ['label' => 'Large label (4 x 6.5 in)', 'width' => 4.0, 'height' => 6.5],
        'label_small' => ['label' => 'Small label (2.312 x 4 in)', 'width' => 2.312, 'height' => 4.0],
        'label_address' => ['label' => 'Address label (1.125 x 3.5 in)', 'width' => 3.5, 'height' => 1.125],
    ],

    // Media pallet labels can be laid out for and printed on (first = default); which one is in
    // use is a setting (LabelSetting, /setup/printers). Each must be a key of 'media' above.
    'pallet_label_media' => ['label_large', 'label_small'],

    // Same for inventory labels (one per item set put on a pallet); small first, since that is the default.
    'inventory_label_media' => ['label_small', 'label_large'],

    // Largest PDF (KB) an administrator can upload to print (POST /json/print-upload).
    'upload_max_kb' => 10240,

    // A job claimed by a print server that never reports back (crash, power
    // loss) returns to the queue after this many minutes.
    'claim_expires_minutes' => 5,

    // Give up re-queueing a job after this many claims that expired without
    // the server reporting back; it then shows as failed for a human to retry
    // or divert. A failure the server *reports* (out of paper, ...) is never
    // retried automatically.
    'max_attempts' => 3,

    // Rendered job PDFs older than this are deleted (days).
    'retain_days' => 14,

    // Where *print servers* reach the realtime server (Laravel Reverb) for
    // instant wake-ups. Leave unset and they use the same address the app
    // publishes to (REVERB_HOST/PORT/SCHEME). Set these only when the app
    // publishes to Reverb locally (e.g. http://127.0.0.1:8080) while devices
    // connect through the public proxy (wss://your.host:443). Browsers use the
    // VITE_REVERB_* values instead. See scripts/REVERB.md.
    'realtime' => [
        'host' => env('REVERB_PUBLIC_HOST'),
        'port' => env('REVERB_PUBLIC_PORT'),
        'scheme' => env('REVERB_PUBLIC_SCHEME'),
    ],

    // Storage disk + directory holding queued job PDFs (never public).
    'disk' => 'local',
    'directory' => 'print-jobs',
];
