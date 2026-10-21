<?php

// Online barcode lookup used to *suggest* details for a UPC we have never seen.
// Only the barcode is ever sent. Results are suggestions a person confirms;
// confirmed ones live in our own upc_mappings table, so each barcode is looked
// up online at most about once.
return [
    'enabled' => env('RI_UPC_LOOKUP', true),

    // Open Food Facts asks every client to identify itself: AppName/Version (contact).
    'user_agent' => env('RI_UPC_USER_AGENT', 'ReliefInventory/1.0 (+'.env('APP_URL', 'https://reliefinventory.fiforms.net').')'),

    'timeout' => 3,

    // Tried in order; the first that knows the barcode wins. Add further free
    // services here (see TODO.md) — each needs a matching case in UpcLookupService::parse().
    'sources' => [
        'openfoodfacts' => [
            'name' => 'Open Food Facts',
            'url' => 'https://world.openfoodfacts.org/api/v2/product/{code}.json',
        ],
        'openproductsfacts' => [
            'name' => 'Open Products Facts',
            'url' => 'https://world.openproductsfacts.org/api/v2/product/{code}.json',
        ],
    ],

    // Both services allow ~15 reads/min per IP and ban abusers; stay under it
    // for the whole install, across every sorter.
    'max_requests_per_minute' => 10,

    'cache_found_days' => 30,
    'cache_none_days' => 3,
];
