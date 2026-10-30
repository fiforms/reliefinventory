<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a fresh warehouse its own starting point for the numbers printed on labels (pallet tags, ORD-/BOL- numbers,
 * inventory labels). Each is just the row id, so two warehouses that both start at 1 print identical labels and a
 * pallet moved between them is ambiguous. A random start makes that collision unlikely; it is not a uniqueness
 * guarantee, and nothing relies on one.
 *
 * Shape for a label of $width digits: first digit 0-5, the next three random, the rest zeros ending in 1
 * (8 digits: 3 472 0001). Only a table with no rows is touched, so re-running it on a live warehouse changes nothing.
 */
class RandomNumberStart
{
    /** table => digits the label is padded to. */
    public const TABLES = [
        'pallets' => 8,
        'orderdonations' => 6,
        'pallet_stock_assignments' => 8,
    ];

    public static function start(int $width): int
    {
        return random_int(0, 5) * 10 ** ($width - 1) + random_int(0, 999) * 10 ** ($width - 4) + 1;
    }

    public static function apply(): void
    {
        foreach (self::TABLES as $table => $width) {
            if (Schema::hasTable($table) && ! DB::table($table)->exists()) {
                DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.self::start($width));
            }
        }
    }
}
