<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ItemType;

class FixItemNumbers extends Command
{
    protected $signature = 'inventory:fix-item-numbers';
    protected $description = 'Ensure all item numbers are exactly 5 digits with zero-padding.';

    public function handle()
    {
        $result = ItemType::ensureFiveDigitItemNumbers();
        $this->info("Process complete. {$result['updated']} item numbers corrected.");
    }
}
