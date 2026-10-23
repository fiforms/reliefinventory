<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

/*
 * Training Mode's Prepare Training (App\Services\TrainingPreparer). The goods are the trainer's real products,
 * scanned on Setup > Training Mode > Scan Training Products; these numbers say how they are spread over the stations.
 *
 * Only what the first team at each station needs is staged: the class rotates station N -> N+1, so after its first
 * stop every team works on the goods it handled itself one station earlier (what Team 1 receives it sorts next, then
 * puts away, ...). Everything left over goes to Receiving, so most of the goods come in through the door for real.
 */
return [

    'stage' => [
        // Station 1: boxes of real goods to receive (no records — they arrive). Each gets a pending offer to match.
        'receiving_boxes' => 5,
        'receiving_box_size' => 10,
        // Station 2: boxes already received (an R pallet each), waiting to be sorted.
        'sorting_boxes' => 2,
        'sorting_box_size' => 10,
        // Station 3: sorted goods on the sorting-area table, not on a pallet yet.
        'sorting_area_units' => 10,
        // Station 4: sealed W pallets (trays) at table spots, and Ready to Fill orders picked from them.
        'rack_pallets' => 4,
        'rack_pallet_size' => 6,
        'orders_to_fill' => 3,
        // Station 5: picked (Filled) orders staged at the door.
        'orders_to_ship' => 2,
        'ship_order_size' => 5,
    ],

    // At least this many different products, so a box isn't all one thing.
    'min_products' => 15,

    // The table spots Station 3 puts pallets on and Station 4 picks from: three tables, four spots each (A-1 ... C-4).
    'tables' => ['A', 'B', 'C'],
    'spots_per_table' => 4,

];
