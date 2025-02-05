<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TestOrderLinesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Retrieve all orderdonations and items from the database
        $orderDonations = DB::table('orderdonations')->pluck('id');
        $items = DB::table('items')->pluck('id');

        // Prepare sample data for item ledgers
        $itemLedgers = [];
        foreach ($orderDonations as $orderDonationId) {
            foreach ($items->random(2) as $itemId) { // Assign 2 random items per orderdonation
                $itemLedgers[] = [
                    'orderdonation_id' => $orderDonationId,
                    'item_id' => $itemId,
                    'qty_added' => random_int(0, 10), // Random quantity added
                    'qty_subtracted' => random_int(0, 5), // Random quantity subtracted
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }
        }

        // Insert data into the item_ledgers table
        DB::table('item_ledgers')->insert($itemLedgers);
    }
}
