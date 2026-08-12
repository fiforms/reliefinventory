<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ItemType;

class RefreshGenericItems extends Command
{
    protected $signature = 'inventory:refresh-generic-items';
    protected $description = 'Ensure every ItemType has a corresponding generic item in Items';

    public function handle()
    {
        $response = ItemType::refreshGenericItems();
        $this->info("Process complete. {$response['created']} generic items added and {$response['updated']} updated.");
    }
}
