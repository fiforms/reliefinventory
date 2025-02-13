<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class ItemTypeSeederCsv extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvFilePath = storage_path('app/Itemtypes.csv');
        
        // Open and read the CSV file
        if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
            $this->command->error("CSV file not found or unreadable: $csvFilePath");
            return;
        }

        $csv = Reader::createFromPath($csvFilePath, 'r');
        $csv->setHeaderOffset(0); // Use first row as header

        $categories = [];
        $itemTypes = [];

        foreach ($csv as $record) {
            $categoryName = trim($record['Category']);
            $itemTypeName = trim($record['Type Name']);
            $unit = trim($record['Unit'] ?? 'each');

            // Ensure category is stored once
            if (!isset($categories[$categoryName])) {
                $categoryId = DB::table('categories')->insertGetId([
                    'name' => $categoryName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $categories[$categoryName] = $categoryId;
            }

            // Store item type with the mapped category ID
            $itemTypes[] = [
                'number' => $record['ACS #'],
                'category_id' => $categories[$categoryName],
                'name' => $itemTypeName,
                'unit_id' => DB::table('units')->where('abbreviation', $unit)->value('id') ?? 1, // Default to 'each'
                'description' => null,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insert item types
        DB::table('itemtypes')->insert($itemTypes);

        $this->command->info('CSV data successfully seeded into categories and itemtypes tables.');
    }
}
