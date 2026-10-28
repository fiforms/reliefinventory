<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\TrainingReset;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;

/**
 * A training warehouse's switch and reset (MULTI_WAREHOUSE.md §12): `warehouse:training enable|disable|reset <id>`. Off is the
 * warehouse's `suspended` status: it refuses everything (logins, /join, /training-login, machine URLs) and drops out of the chooser,
 * its data kept. Reset saves the class archive, then rebuilds that one warehouse from its seed here and now (Setup > Training
 * Warehouse does the same in the background). The instance console (phase 5) will offer all three.
 */
class WarehouseTraining extends Command
{
    protected $signature = 'warehouse:training {action : enable, disable or reset} {warehouse : the training warehouse id}';

    protected $description = 'Turn a training warehouse on or off, or reset it to its seed.';

    public function handle(WarehouseContext $context, TrainingReset $reset): int
    {
        $warehouse = Warehouse::find(strtoupper((string) $this->argument('warehouse')));
        if (! $warehouse || ! $warehouse->is_training) {
            $this->components->error("There is no training warehouse {$this->argument('warehouse')}. `php artisan warehouse:list` shows them.");

            return self::FAILURE;
        }

        switch ($this->argument('action')) {
            case 'enable':
                if ($warehouse->status !== 'suspended') {
                    $this->components->error("{$warehouse->display_name} is {$warehouse->status}, not off.");

                    return self::FAILURE;
                }
                $warehouse->forceFill(['status' => 'active'])->save();
                $this->components->info("{$warehouse->display_name} is on.");

                return self::SUCCESS;
            case 'disable':
                if ($warehouse->status !== 'active') {
                    $this->components->error("{$warehouse->display_name} is {$warehouse->status}, not on.");

                    return self::FAILURE;
                }
                $warehouse->forceFill(['status' => 'suspended'])->save();
                $this->components->info("{$warehouse->display_name} is off. Its data is kept; `enable` turns it back on, `reset` clears it first.");

                return self::SUCCESS;
            case 'reset':
                return $this->reset($context, $reset, $warehouse);
        }

        $this->components->error('The action is enable, disable or reset.');

        return self::FAILURE;
    }

    private function reset(WarehouseContext $context, TrainingReset $reset, Warehouse $warehouse): int
    {
        if (! in_array($warehouse->status, ['active', 'suspended', 'restoring'], true)) {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}; it can't be reset now.");

            return self::FAILURE;
        }
        $this->components->info("Resetting {$warehouse->display_name}: saving the class archive, then rebuilding it from its seed.");

        return $context->run($warehouse, function () use ($reset) {
            $stamp = $reset->archive();
            $reset->run($stamp);
            $status = $reset->status();
            if ($status['state'] !== 'complete') {
                $this->components->error('The reset failed: '.($status['error'] ?? 'unknown error').' The warehouse is left unavailable; run the reset again to finish it.');

                return self::FAILURE;
            }
            $this->components->info("Done. The class archive is {$stamp}.");

            return self::SUCCESS;
        });
    }
}
