<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\PackageType;

class ItemSeederCsv extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $filePath = storage_path('app/Items.csv');
        
        if (!file_exists($filePath)) {
            $this->command->error("CSV file not found at {$filePath}");
            return;
        }
        
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0); // Assumes first row is the header
        
        foreach ($csv as $record) {
            $itemType = ItemType::where('name', $record['Item Type Name'])->first();
            $packageType = PackageType::where('singular', $record['Unit'])->orWhere('plural', $record['Unit'])->first();

            if (!$itemType || !$packageType) {
                $this->command->warn("Skipping item: {$record['Item Description']} due to missing type or package");
                continue;
            }
            
            Item::updateOrCreate(
                ['upc' => $record['UPC']],
                [
                    'description' => $record['Item Description'],
                    'case_qty' => $record['Case Qty'],
                    'size' => $record['Size'],
                    'itemtype_id' => $itemType->id,
                    'packagetypes_id' => $packageType->id,
                    'active' => 1
                ]
            );
        }
        
        $this->command->info('Items table seeded successfully!');
    }
}
