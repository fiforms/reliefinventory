<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PackageTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample data for the `items` table
        $pkg = [
            [
                'singular' => 'Unit',
                'plural'   => 'Units',
            ],
            [
                'singular' => 'Case',
                'plural'   => 'Cases',
                
            ],
            [
                'singular' => 'Bag',
                'plural'   => 'Bags',
                
            ],
            [
                'singular' => 'Pallet',
                'plural'   => 'Pallets',
            ],
            [
                'singular' => 'Gallon',
                'plural'   => 'Gallons',
            ],
            [
                'singular' => 'Ton',
                'plural'   => 'Tons',
            ],
            [
                'singular' => 'Shipping Container',
                'plural'   => 'Shipping Containers',
            ],
            [
                'singular' => 'Truckload',
                'plural'   => 'Truckloads',
            ],
        ];
        // Insert data into the items table
        DB::table('packagetypes')->insert($pkg);
    }
}
