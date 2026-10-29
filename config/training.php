<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

/*
 * Training Mode's Prepare Training (App\Services\TrainingPreparer). Receiving and Sorting use the trainer's own goods,
 * staged by hand and never scanned: Prepare Training only makes the offers Receiving matches deliveries to; Sorting sorts
 * what Receiving brings in. Stations 3-5 work on goods the system must already know are there, so those are the
 * trainer's real products scanned on Setup > Training Mode > Scan Training Products, spread as below.
 *
 * Only what the first team at each station needs is staged: the class rotates station N -> N+1, so after its first
 * stop every team works on the goods it handled itself one station earlier.
 */
return [

    'stage' => [
        // Station 1: pending donation offers for the goods the trainer puts out to receive.
        'offers' => 5,
        // Station 3: scanned goods on the sorting-area table, sorted but not on a pallet yet.
        'sorting_area_units' => 10,
        // Station 4: sealed W pallets (trays) at table spots, and Ready to Fill orders picked from them. Scanned items
        // beyond what Stations 3-5 need are added to these trays.
        'rack_pallets' => 4,
        'rack_pallet_size' => 6,
        'orders_to_fill' => 3,
        // Station 5: picked (Filled) orders staged at the door.
        'orders_to_ship' => 2,
        'ship_order_size' => 5,
    ],

    // At least this many different scanned products, so a tray or load isn't all one thing.
    'min_products' => 10,

    // The table spots Station 3 puts pallets on and Station 4 picks from: three tables, four spots each (A-1 ... C-4).
    'tables' => ['A', 'B', 'C'],
    'spots_per_table' => 4,

];
