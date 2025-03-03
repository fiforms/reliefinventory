<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Useful for importing domains from https://github.com/disposable-email-domains/disposable-email-domains

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportBannedEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:import-banned {filepath}';
    
    protected $description = 'Import a list of disposable email domains into the banned_emails table';
    
    public function handle()
    {
        $filepath = $this->argument('filepath');
        
        if (!File::exists($filepath)) {
            $this->error("File not found: $filepath");
            return 1; // Exit with an error status
        }
        
        $domains = File::lines($filepath)->map(fn($line) => trim($line))
        ->filter(fn($line) => !empty($line) && !DB::table('banned_emails')->where('pattern', '*@' . $line)->exists())
        ->map(fn($line) => ['pattern' => '*@' . $line, 'created_at' => now(), 'updated_at' => now()])
        ->toArray();
        
        if (!empty($domains)) {
            DB::table('banned_emails')->insert($domains);
            $this->info(count($domains) . " domains added to the banned list.");
        } else {
            $this->info("No new domains to import.");
        }
        
        return 0; // Successful execution
    }

}
