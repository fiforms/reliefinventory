<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseRemoval;
use Illuminate\Console\Command;
use RuntimeException;

/** The undoable first step of removing a warehouse (MULTI_WAREHOUSE.md §6): it stops serving and keeps everything. `--undo` puts it back. */
class WarehouseArchive extends Command
{
    protected $signature = 'warehouse:archive {warehouse : the warehouse id} {--undo : bring an archived warehouse back as it was}';

    protected $description = 'Archive a warehouse (stops serving, keeps all its data), or bring it back with --undo.';

    public function handle(WarehouseRemoval $removal): int
    {
        $warehouse = Warehouse::find(strtoupper((string) $this->argument('warehouse')));
        if (! $warehouse) {
            $this->components->error("There is no warehouse {$this->argument('warehouse')}. `php artisan warehouse:list` shows them.");

            return self::FAILURE;
        }

        try {
            if ($this->option('undo')) {
                $removal->unarchive($warehouse);
                $this->components->info("{$warehouse->display_name} is {$warehouse->status} again.");
            } else {
                $removal->archive($warehouse);
                $this->components->info("{$warehouse->display_name} is archived: it no longer serves anyone, and nothing was deleted. `--undo` brings it back; `warehouse:remove` removes it for good.");
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
