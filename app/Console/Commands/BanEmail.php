<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BanEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:ban {pattern}';
    
    protected $description = 'Add a single email or wildcard pattern to the banned_emails table';
    
    public function handle()
    {
        $pattern = trim($this->argument('pattern'));
        
        if (empty($pattern)) {
            $this->error("Pattern cannot be empty.");
            return 1;
        }
        
        // Ensure domain patterns start with *@
        if (!str_contains($pattern, '@')) {
            $pattern = "*@{$pattern}";
        }
        
        // Check if the pattern already exists
        if (DB::table('banned_emails')->where('pattern', $pattern)->exists()) {
            $this->warn("The pattern '{$pattern}' is already in the banned list.");
            return 0;
        }
        
        // Insert into database
        DB::table('banned_emails')->insert([
            'pattern' => $pattern,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $this->info("The pattern '{$pattern}' has been added to the banned list.");
        return 0;
        
    }

}
