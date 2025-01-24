<?php

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
                'type' => 'each',
            ],
            [
                'type' => 'case',
            ],
            [
                'type' => 'bag',
            ],
            [
                'type' => 'other',
            ],
        ];
        // Insert data into the items table
        DB::table('packagetypes')->insert($pkg);
    }
}
