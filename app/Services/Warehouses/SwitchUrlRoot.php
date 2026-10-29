<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\URL;

/**
 * Outside a request (queued jobs, commands) there is no address to build URLs from but APP_URL, so they are rooted
 * at the warehouse's public address instead (its own hostname when it has one, MULTI_WAREHOUSE.md §3). A request
 * keeps the address it arrived on, so a LAN visitor who came by IP address is sent back there; links that leave
 * the browser (emails) never use it — they come from Warehouse::publicUrl().
 */
class SwitchUrlRoot implements SwitchTask
{
    public function makeCurrent(Warehouse $warehouse): void
    {
        if ($this->outsideRequests()) {
            URL::forceRootUrl($warehouse->publicRoot());
        }
    }

    public function forgetCurrent(): void
    {
        if ($this->outsideRequests()) {
            URL::forceRootUrl(null);
        }
    }

    private function outsideRequests(): bool
    {
        return app()->runningInConsole() && ! app()->runningUnitTests();
    }
}
