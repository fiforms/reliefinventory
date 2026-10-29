<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [

    /*
    |--------------------------------------------------------------------------
    | Low stock threshold
    |--------------------------------------------------------------------------
    |
    | Global default for the Available/Limited/Unavailable three-state
    | availability computation (dev/FACILITY_NETWORK.md). An itemtype's
    | on-hand at or below this is "Limited" (or "Unavailable" at zero);
    | above it is "Available". Overridable per itemtype via
    | itemtypes.low_stock_threshold.
    |
    */

    'low_stock_threshold' => env('INVENTORY_LOW_STOCK_THRESHOLD', 10),

    /*
    |--------------------------------------------------------------------------
    | Donor standing suggestions
    |--------------------------------------------------------------------------
    |
    | The Donor Report suggests a standing from how much of a donor's sorted
    | goods were lost (outdated + trashed, over everything sorted). A person
    | with manage-donor-standing makes the actual call. Starting values to
    | test and refine; every one is overridable here or via .env.
    |
    | A donor needs at least min_donations sorted donations AND min_units
    | units before any suggestion is made (a first bad pallet isn't a
    | pattern); below that the report says "not enough data".
    |
    */

    'donor_standing' => [
        'min_donations' => (int) env('INVENTORY_DONOR_MIN_DONATIONS', 2),
        'min_units' => (int) env('INVENTORY_DONOR_MIN_UNITS', 50),
        'watch_loss_percent' => (float) env('INVENTORY_DONOR_WATCH_LOSS', 30),
        'decline_loss_percent' => (float) env('INVENTORY_DONOR_DECLINE_LOSS', 50),
        'decline_min_donations' => (int) env('INVENTORY_DONOR_DECLINE_MIN_DONATIONS', 3),
        'preferred_loss_percent' => (float) env('INVENTORY_DONOR_PREFERRED_LOSS', 5),
        'preferred_min_donations' => (int) env('INVENTORY_DONOR_PREFERRED_MIN_DONATIONS', 5),
        'preferred_min_units' => (int) env('INVENTORY_DONOR_PREFERRED_MIN_UNITS', 500),
    ],

];
