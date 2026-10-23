<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

/*
 * Training Mode's Prepare Training (App\Services\TrainingPreparer): the starter work loaded for every station
 * so all teams can start at once, and what the setup packet tells trainers to stage on the floor. Edit to match
 * the props you actually have.
 */
return [

    // Item types the mock warehouse stocks, by ACS number (Master Item List), with the starter quantity of each
    // that is "already sorted" (on the shelf and in the sorting area). A number not in this instance's list is
    // skipped and reported.
    'items' => [
        ['number' => '180', 'qty' => 120],     // Water
        ['number' => '101', 'qty' => 80],      // Canned food, mixed
        ['number' => '144', 'qty' => 60],      // Snacks, any kind
        ['number' => '332', 'qty' => 60],      // Toilet paper, household
        ['number' => '318', 'qty' => 48],      // Paper towels, household rolls
        ['number' => '224', 'qty' => 40],      // Personal wipes
        ['number' => '206', 'qty' => 40],      // Soap / body wash, mixed
        ['number' => '203', 'qty' => 40],      // Toothpaste
        ['number' => '724', 'qty' => 30],      // Bleach / OxiClean
        ['number' => '730', 'qty' => 30],      // Gloves, cleaning
        ['number' => '710', 'qty' => 20],      // Flood buckets
        ['number' => '950-11', 'qty' => 20],   // Tarps, 8x10
    ],

    // How much starter work each station gets. Five teams visit every station twice (Round 1, Round 2).
    'counts' => [
        'offers' => 5,              // Station 1: pending donation offers ("items waiting to be offered" arrive against these)
        'pallets_to_sort' => 10,    // Station 2: received R pallets waiting for sorting
        'warehouse_pallets' => 6,   // Station 3/4: sealed W pallets on racks, built from the starter stock
        'orders_to_fill' => 10,     // Station 4: Ready to Fill orders
        'orders_to_ship' => 10,     // Station 5: Filled orders
        'orders_to_review' => 5,    // Station 6: Delivered orders with a signed BOL waiting for review
    ],

    // Share of each item's starter quantity put on the sealed W pallets; the rest stays in the sorting area for
    // Station 3 to build new pallets from.
    'on_pallets_share' => 0.5,

];
