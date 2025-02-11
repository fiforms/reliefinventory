<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use App\Models\Location;
use App\Models\UseModel;

class LocationSeederCsv extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvFilePath = storage_path('app/Locations.csv');

        if (!file_exists($csvFilePath)) {
            $this->command->error("CSV file not found at: {$csvFilePath}");
            return;
        }

        $csv = Reader::createFromPath($csvFilePath, 'r');
        $csv->setHeaderOffset(0);

        foreach ($csv as $row) {
            if (empty($row['Full_Lane_ID']) || empty($row['PullSequence'])) {
                continue; // Skip empty rows
            }

            // Look up use_id from 'uses' table
            $useId = null;
            if (!empty($row['Lane Use'])) {
                $use = UseModel::where('use', $row['Lane Use'])->first();
                $useId = $use ? $use->id : null;
            }

            // Insert into locations table
            Location::updateOrCreate(
                ['code' => $row['Full_Lane_ID']],
                [
                    'PullSequence' => $row['PullSequence'],
                    'Route'        => $row['Route'] ?? null,
                    'Block'        => $row['Block'] ?? null,
                    'Aisle'        => $row['Aisle'] ?? null,
                    'Lane'         => $row['Lane'] ?? null,
                    'Stack'        => $row['ShortLane_ID'] ?? null,
                    'use_id'       => $useId,
                    'status'       => 'active',
                ]
            );
        }

        $this->command->info('Location data seeded successfully.');
    }
}
