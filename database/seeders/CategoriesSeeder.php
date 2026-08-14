<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The 15 numbering-scheme category blocks from HANDOFF-item-numbering.md.
 * A family's own leading digits place it in its block, so this table exists
 * only to give itemtypes a category_id to point at and to group/validate by
 * block range — not a parent/child hierarchy.
 */
class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Animal products & durable medical', 'block_start' => 0, 'block_end' => 99],
            ['name' => 'Food & water', 'block_start' => 100, 'block_end' => 199],
            ['name' => 'Personal care, pharmacy & safety', 'block_start' => 200, 'block_end' => 299],
            ['name' => 'Paper & disposables', 'block_start' => 300, 'block_end' => 399],
            ['name' => 'Baby & child', 'block_start' => 400, 'block_end' => 499],
            ['name' => 'Bedding & linens', 'block_start' => 500, 'block_end' => 599],
            ['name' => 'Kitchen & dining', 'block_start' => 600, 'block_end' => 699],
            ['name' => 'Cleaning & household', 'block_start' => 700, 'block_end' => 799],
            ['name' => 'Clothing & accessories', 'block_start' => 800, 'block_end' => 899],
            ['name' => 'Outdoor, emergency & tools', 'block_start' => 900, 'block_end' => 999],
            ['name' => 'Appliances, large (team-lift)', 'block_start' => 1000, 'block_end' => 1399],
            ['name' => 'Appliances, small (countertop)', 'block_start' => 1500, 'block_end' => 1699],
            ['name' => 'Building supplies', 'block_start' => 2000, 'block_end' => 2999],
            ['name' => 'Furniture', 'block_start' => 3000, 'block_end' => 3999],
            ['name' => 'Holy Bible', 'block_start' => 7777, 'block_end' => 7777],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['name' => $category['name']], $category);
        }
    }
}
