<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [
    // A device whose last heartbeat is older than this is shown as offline
    // (heartbeats are every 5 minutes; allow a few missed ones).
    'offline_after_minutes' => 15,

    // Chunked firmware upload (DeviceReleaseController): the size the browser slices files into
    // — under a default proxy's 1 MB request-body limit — and the largest file accepted.
    'upload_chunk_bytes' => (int) env('DEVICE_UPLOAD_CHUNK_BYTES', 1_000_000),
    'max_release_bytes' => (int) env('DEVICE_MAX_RELEASE_BYTES', 8 * 1024 * 1024 * 1024),

    'channels' => ['stable', 'testing', 'developer'],

    // What a kiosk can be configured to do, sent down in the heartbeat as
    // `kiosk_function` / `kiosk_path`. `path` is the app page the kiosk's
    // browser should show; null = headless/not built yet.
    'functions' => [
        'volunteer_checkin' => ['label' => 'Volunteer check-in', 'path' => '/volunteers/kiosk'],
        'inventory_sorting' => ['label' => 'Inventory sorting', 'path' => '/donation-sorting'],
        // Chrome-free, auto-refreshing stats screens for an always-on display (no login; see DeviceSessionController).
        'status_sitrep' => ['label' => 'Situation report display', 'path' => '/display/sitrep'],
        'status_dashboard' => ['label' => 'Warehouse stats display', 'path' => '/display/dashboard'],
        'driver_checkin' => ['label' => 'Driver check-in/out', 'path' => null],
        'print_station' => ['label' => 'Print station (headless)', 'path' => null],
    ],
];
