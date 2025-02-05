<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TestItemsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample data for the `items` table
        $items = [
            [
                'itemtype_id' => 1,
                'pluscode' => '0001',
                'upc' => '023545207024',
                'packagetypes_id' => 1,
                'size' => 48.0,
                'description' => 'La Fe Dry Pinto Beans Bag',
                'case_qty' => null,
                'active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'itemtype_id' => 1,
                'pluscode' => '0002',
                'upc' => '690164577175',
                'packagetypes_id' => 2,
                'size' => 32.0,
                'description' => 'Racor Dry Red Beans 32oz - Frijoles Rojo De Seda (Pack of 6)',
                'case_qty' => 6,
                'active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'itemtype_id' => 2,
                'pluscode' => '0001',
                'upc' => '082657874449',
                'packagetypes_id' => 2,
                'size' => 16.9,
                'description' => 'Deer Park Brand 100% Natural Spring Water - 12pk/16.9 Fl Oz Bottles',
                'case_qty' => 12,
                'active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'itemtype_id' => 5,
                'pluscode' => '0001',
                'upc' => '400316419686',
                'packagetypes_id' => 1,
                'size' => null,
                'description' => 'Oral-B Kids Timer with Lights Toothbrush, Blue',
                'case_qty' => null,
                'active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        // Insert data into the items table
        DB::table('items')->insert($items);
    }
}
