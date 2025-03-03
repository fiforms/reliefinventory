<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\County;
use Illuminate\Support\Facades\Storage;

class ImportCountiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'counties:import {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import counties from a text file (From https://www.census.gov/library/reference/code-lists/ansi.html#cou)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists(base_path($filePath))) {
            $this->error("File not found: $filePath");
            return;
        }

        $content = file_get_contents(base_path($filePath));
        $lines = explode("\n", trim($content));

        if (count($lines) <= 1) {
            $this->error("File appears to be empty or incorrectly formatted.");
            return;
        }

        // Process each line and insert into the database
        $bar = $this->output->createProgressBar(count($lines));
        $bar->start();

        foreach ($lines as $line) {
            $columns = explode('|', $line);

            if (count($columns) !== 7) {
                $this->warn("Skipping invalid line: $line");
                continue;
            }
            if (!(int)$columns[1]) {
                $this->warn("Invalid State ID: $columns[1] on line $line");
                continue;
            }

            list($state, $statefp, $countyfp, $countyns, $countyname, $classfp, $funcstat) = $columns;

            County::updateOrCreate(
                [
                    'id' => (int)$statefp * 1000 + (int)$countyfp,
                    'state' => $state,
                    'county' => $countyname,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $bar->advance();
        }

        $bar->finish();
        $this->info("\nCounty data imported successfully.");
    }
}
