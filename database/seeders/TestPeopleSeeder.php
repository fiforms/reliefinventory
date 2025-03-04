<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TestPeopleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sample data for the `people` table
        $people = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'organization' => 'Helping Hands Foundation',
                'phone' => '555-123-4567',
                'email' => 'john.doe@example.com',
                'address' => '123 Elm Street',
                'city' => 'Greensboro',
                'state' => 'NC',
                'zip' => '27401',
                'comments' => 'A regular customer.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'organization' => 'Kind Hearts Inc.',
                'phone' => '555-987-6543',
                'email' => 'jane.smith@example.com',
                'address' => '456 Oak Avenue',
                'city' => 'Charlotte',
                'state' => 'NC',
                'zip' => '28202',
                'comments' => 'A generous donor who frequently contributes.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        // Insert data into the people table
        DB::table('people')->insert($people);
    }
}
