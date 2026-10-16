<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [
    // Minutes a generated pairing code stays valid.
    'pairing_code_minutes' => 15,

    // A device whose last heartbeat is older than this is shown as offline
    // (heartbeats are every 5 minutes; allow a few missed ones).
    'offline_after_minutes' => 15,

    'channels' => ['stable', 'testing', 'developer'],

    // What a kiosk can be configured to do, sent down in the heartbeat as
    // `kiosk_function` / `kiosk_path`. `path` is the app page the kiosk's
    // browser should show; null = headless/not built yet.
    'functions' => [
        'volunteer_checkin' => ['label' => 'Volunteer check-in', 'path' => '/volunteers/kiosk'],
        'inventory_sorting' => ['label' => 'Inventory sorting', 'path' => '/donation-sorting'],
        'driver_checkin' => ['label' => 'Driver check-in/out', 'path' => null],
        'print_station' => ['label' => 'Print station (headless)', 'path' => null],
    ],
];
