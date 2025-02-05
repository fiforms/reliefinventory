<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample data for the `items` table
        $units = [
            [
                'abbreviation' => 'lb',
                'name' => 'pound',
                'type' => 'weight',
            ],
            [
                'abbreviation' => 'oz',
                'name' => 'ounce',
                'type' => 'weight',
            ],
            [
                'abbreviation' => 'kg',
                'name' => 'kilogram',
                'type' => 'weight',
            ],
            [
                'abbreviation' => 'g',
                'name' => 'gram',
                'type' => 'weight',
            ],
            [
                'abbreviation' => 'gal',
                'name' => 'gallon',
                'type' => 'volume',
            ],
            [
                'abbreviation' => 'each',
                'name' => 'each',
                'type' => 'each',
            ],
            [
                'abbreviation' => 'unknown',
                'name' => 'unknown',
                'type' => 'other',
            ],
        ];
        // Insert data into the items table
        DB::table('units')->insert($units);
    }
}
