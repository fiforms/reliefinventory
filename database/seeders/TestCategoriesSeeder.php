<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TestCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample data for the `categories` table
        $categories = [
            [
                'name' => 'Food',
                'measurement_unit' => 'kg', // Measurement unit for this category
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Clothing',
                'measurement_unit' => 'pieces', // Measurement unit for this category
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Hygiene Supplies',
                'measurement_unit' => 'packs', // Measurement unit for this category
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        // Insert data into the categories table
        DB::table('categories')->insert($categories);
    }
}
