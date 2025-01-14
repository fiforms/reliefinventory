<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TestOrdersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Generate test data for `orderdonations` table
        $testData = [
            [
                'type' => 'order',
                'person_id' => 1, 
                'status_id' => 2, 
                'user_id' => 1,   
                'order_date' => Carbon::today()->subDays(5)->toDateString(),
                'comments' => 'This is a test order.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'type' => 'donation',
                'person_id' => 1, 
                'status_id' => 4,
                'user_id' => 1,   
                'order_date' => Carbon::today()->subDays(3)->toDateString(),
                'comments' => 'This is a test donation.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            // Add more rows as needed
        ];

        // Insert data into the orderdonations table
        DB::table('orderdonations')->insert($testData);
    }
}
