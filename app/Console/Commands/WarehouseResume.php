<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use Illuminate\Console\Command;

/**
 * Puts a warehouse back in service after a restore or reset left it out of it (status `restoring`) — on purpose, because a database
 * that failed to load has no setup record and would open the setup wizard to anyone. Replaces `php artisan up`, which was instance-wide.
 */
class WarehouseResume extends Command
{
    protected $signature = 'warehouse:resume {warehouse : the warehouse id}';

    protected $description = 'Put a warehouse that a failed restore left unavailable back in service.';

    public function handle(): int
    {
        $warehouse = Warehouse::find(strtoupper((string) $this->argument('warehouse')));
        if (! $warehouse) {
            $this->components->error("There is no warehouse {$this->argument('warehouse')}. `php artisan warehouse:list` shows them.");

            return self::FAILURE;
        }
        if ($warehouse->status !== 'restoring') {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}, not restoring; there is nothing to resume.");

            return self::FAILURE;
        }

        $warehouse->forceFill(['status' => 'active'])->save();
        InstanceAuditLog::record('warehouse.resumed', $warehouse);
        $this->components->info("{$warehouse->display_name} is back in service.");

        return self::SUCCESS;
    }
}
