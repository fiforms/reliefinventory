<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;


class TestItemTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
      
            // Example itemtypes to seed
            $itemtypes = [
                [
                    'number' => '00136',
                    'category_id' => 1,
                    'unit_id' => 1,
                    'name' => 'Food Dry Beans',
                    'description' => 'Dried beans, pinto beans, legumes, non-perishable',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'number' => '00180',
                    'category_id' => 1, 
                    'unit_id' => 2,
                    'name' => 'Water',
                    'description' => 'Bottled Water',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'number' => '00834',
                    'category_id' => 2, 
                    'unit_id' => 6,
                    'name' => 'T-Shirts',
                    'description' => 'Shirts, casual, plain or lettered Tshirts, T-Shirts',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'number' => '00740',
                    'category_id' => 2, 
                    'unit_id' => 6,
                    'name' => 'Trash Can',
                    'description' => 'Trash Can, Garbage Can, waste baskets',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'number' => '00202',
                    'category_id' => 3, 
                    'unit_id' => 6,
                    'name' => 'Toothbrush',
                    'description' => 'Tooth Brushes, Dental Care Brushes',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'number' => '00000',
                    'category_id' => 3, 
                    'unit_id' => 7,
                    'name' => 'Unknown',
                    'description' => 'Default item type for unlinked items.',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];
            
            // Insert data into the itemtypes table
            DB::table('itemtypes')->insert($itemtypes);
        }
}
