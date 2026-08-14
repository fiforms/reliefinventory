<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use App\Models\ContainerType;
use Illuminate\Database\Seeder;

class ContainerTypesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Box', 'Bin', 'Bag'] as $name) {
            ContainerType::firstOrCreate(['name' => $name]);
        }
    }
}
