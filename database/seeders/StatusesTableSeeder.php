<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StatusesTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('statuses')->insert([
            ['name' => 'Shopping Cart', 'description' => 'Order is being prepared for checkout.'],
            ['name' => 'New Order', 'description' => 'Order has been placed and is pending processing.'],
            ['name' => 'Picking In Progress', 'description' => 'Items are being picked for the order.'],
            ['name' => 'Completed', 'description' => 'Order has been fulfilled and completed.'],
        ]);
    }
}

