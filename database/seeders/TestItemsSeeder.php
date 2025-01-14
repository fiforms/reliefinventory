<?php

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
                'item_number' => '999001',
                'category_id' => 3, 
                'counted_by' => 'each',
                'size' => 1.50,
                'case_qty' => null,
                'active' => 1,
                'description' => 'Diapers',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'item_number' => '999002',
                'category_id' => 1, 
                'counted_by' => 'case',
                'size' => 12.00,
                'case_qty' => 24,
                'active' => 1,
                'description' => 'Milk',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'item_number' => '999003',
                'category_id' => 1, 
                'counted_by' => 'each',
                'size' => 2.75,
                'case_qty' => null,
                'active' => 1,
                'description' => 'Butter',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'item_number' => '999004',
                'category_id' => 1,
                'counted_by' => 'case',
                'size' => 8.00,
                'case_qty' => 12,
                'active' => 0, // Inactive item for testing
                'description' => 'Bread',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        // Insert data into the items table
        DB::table('items')->insert($items);
    }
}
