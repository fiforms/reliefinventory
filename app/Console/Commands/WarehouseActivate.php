<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InstanceCommand;
use App\Models\Control\InstanceAuditLog;
use Illuminate\Console\Command;

/**
 * Put a suspended or maintenance warehouse back in service. Every other status has its own way back (and an instruction here, rather than a
 * silent flip that could open a warehouse whose database isn't ready): a failed restore is `warehouse:resume`, an archived one
 * `warehouse:archive --undo`, a new one is activated by whoever holds its setup code.
 */
class WarehouseActivate extends Command
{
    use InstanceCommand;

    protected $signature = 'warehouse:activate {warehouse : the warehouse id}';

    protected $description = 'Put a suspended (or maintenance) warehouse back in service.';

    public function handle(): int
    {
        if (! $warehouse = $this->warehouseOrNull($this->argument('warehouse'))) {
            return self::FAILURE;
        }

        if ($warehouse->status === 'active') {
            $this->components->warn("{$warehouse->display_name} is already active.");

            return self::SUCCESS;
        }
        if (! in_array($warehouse->status, ['suspended', 'maintenance'], true)) {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}: ".match ($warehouse->status) {
                'restoring' => '`php artisan warehouse:resume '.$warehouse->id.'` ends a restore that left it out of service (check the restore log first).',
                'archived' => '`php artisan warehouse:archive '.$warehouse->id.' --undo` brings an archived warehouse back.',
                'awaiting_setup' => 'it goes active when the holder of its setup code finishes setting it up (`warehouse:setup-code '.$warehouse->id.'` issues a new code).',
                'provisioning' => '`php artisan warehouse:create --resume='.$warehouse->id.'` finishes creating it.',
                default => 'it is in the middle of an upgrade or held after one; see MULTI_WAREHOUSE.md §10.',
            });

            return self::FAILURE;
        }

        $was = $warehouse->status;
        $warehouse->forceFill(['status' => 'active'])->save();
        InstanceAuditLog::record('warehouse.activated', $warehouse, ['was' => $was]);
        $this->components->info("{$warehouse->display_name} is active again.");

        return self::SUCCESS;
    }
}
