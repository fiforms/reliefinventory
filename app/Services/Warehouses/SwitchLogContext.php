<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\Log;

/** Every log line says which warehouse wrote it: the logs are instance-wide (MULTI_WAREHOUSE.md §13). */
class SwitchLogContext implements SwitchTask
{
    public function makeCurrent(Warehouse $warehouse): void
    {
        Log::shareContext(['warehouse' => $warehouse->id]);
    }

    public function forgetCurrent(): void
    {
        Log::shareContext(['warehouse' => null]);
    }
}
