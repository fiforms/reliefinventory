<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Call test-specific seeders here
        $this->call(TestCategoriesSeeder::class);
        $this->call(TestItemTypesSeeder::class);
        $this->call(TestItemsSeeder::class);
        $this->call(TestPeopleSeeder::class);
        $this->call(TestOrdersSeeder::class);
        $this->call(TestOrderLinesSeeder::class);
        
    }
}
