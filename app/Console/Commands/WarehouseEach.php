<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs a command once in every active warehouse (MULTI_WAREHOUSE.md §9) — what the scheduler uses for per-warehouse
 * work (`warehouse:each "volunteers:sync-active-windows"`). One warehouse failing doesn't stop the others; the
 * command fails if any did.
 */
class WarehouseEach extends Command
{
    protected $signature = 'warehouse:each {line : The command to run, with its arguments, quoted as one argument}';

    protected $description = 'Run a command in every active warehouse.';

    public function handle(WarehouseContext $context): int
    {
        $line = trim((string) $this->argument('line'));
        if ($line === '' || str_contains($line, '--warehouse')) {
            $this->error('Give the command to run, without --warehouse (each warehouse is chosen in turn).');

            return self::FAILURE;
        }

        $failed = 0;
        foreach (Warehouse::where('status', 'active')->orderByDesc('is_default')->orderBy('created_at')->get() as $warehouse) {
            $this->components->info("{$warehouse->id} ({$warehouse->display_name}): {$line}");
            try {
                $code = $context->run($warehouse, fn () => Artisan::call($line, [], $this->output));
            } catch (\Throwable $e) {
                $this->components->error($e->getMessage());
                $code = self::FAILURE;
            }
            $failed += $code === 0 ? 0 : 1;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
