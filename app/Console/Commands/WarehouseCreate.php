<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseCreator;
use Illuminate\Console\Command;

/**
 * Makes a new empty warehouse and prints its address and one-time setup code (MULTI_WAREHOUSE.md §7). The instance console
 * (phase 5) will do the same from the browser. With --resume=<id>, finishes one stuck in `provisioning`; with --new-code=<id>,
 * replaces the setup code of one still awaiting setup.
 */
class WarehouseCreate extends Command
{
    protected $signature = 'warehouse:create {name? : Display name} {--email= : Contact for the person who will run it} {--resume= : Finish a warehouse stuck in provisioning} {--new-code= : Issue a new setup code for a warehouse awaiting setup}';

    protected $description = 'Create a new, empty warehouse and print its setup code.';

    public function handle(WarehouseCreator $creator, SetupCodes $codes): int
    {
        try {
            if ($id = $this->option('new-code')) {
                $warehouse = Warehouse::findOrFail(strtoupper($id));
                if (! $warehouse->isAwaitingSetup()) {
                    $this->components->error("Warehouse {$warehouse->id} is {$warehouse->status}; only one awaiting setup has a setup code.");

                    return self::FAILURE;
                }
                [$code] = $codes->issue($warehouse, 'warehouse:create');
            } elseif ($id = $this->option('resume')) {
                $warehouse = Warehouse::findOrFail(strtoupper($id));
                $code = $creator->provision($warehouse, 'warehouse:create');
            } elseif ($name = $this->argument('name')) {
                [$warehouse, $code] = $creator->create($name, $this->option('email') ?: null, 'warehouse:create');
            } else {
                $this->components->error('Give the new warehouse a name, or --resume=<id> / --new-code=<id>.');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Warehouse {$warehouse->id} ({$warehouse->display_name}) is waiting to be set up.");
        $this->line('  Address:    '.$warehouse->setupUrl());
        $this->line("  Setup code: {$code}  (shown once; valid ".config('warehouses.setup_code_days').' days)');

        return self::SUCCESS;
    }
}
