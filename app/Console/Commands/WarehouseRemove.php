<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseRemoval;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Removes an archived warehouse for good (MULTI_WAREHOUSE.md §6): a final full backup, then its schema, database user, files and key, the
 * logins that belonged only to it, and its control rows. The name has to be typed (here with --confirm, or at the prompt); there is no
 * waiting period, because the instance admin could do the same on the host. Run it again after an interruption to finish.
 */
class WarehouseRemove extends Command
{
    protected $signature = 'warehouse:remove {warehouse : the archived warehouse id} {--confirm= : the warehouse\'s name, typed} {--no-backup : skip the final backup (only for a warehouse with no database left)}';

    protected $description = 'Remove an archived warehouse for good: final backup, then everything it owned.';

    public function handle(WarehouseRemoval $removal): int
    {
        $warehouse = Warehouse::find(strtoupper((string) $this->argument('warehouse')));
        if (! $warehouse) {
            $this->components->error("There is no warehouse {$this->argument('warehouse')}. `php artisan warehouse:list` shows them.");

            return self::FAILURE;
        }
        if ($warehouse->status !== 'archived') {
            $this->components->error("{$warehouse->display_name} is {$warehouse->status}. Archive it first: php artisan warehouse:archive {$warehouse->id}");

            return self::FAILURE;
        }

        $typed = $this->option('confirm') ?? $this->ask("This removes \"{$warehouse->display_name}\" and its data for good (a final backup is taken first). Type its name to continue");
        try {
            $result = $removal->remove($warehouse, (string) $typed, null, ! $this->option('no-backup'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$warehouse->display_name} was removed.");
        $this->line('  Final backup: '.($result['backup'] ?? 'none (skipped)'));
        $this->line('  Logins removed with it: '.($result['accounts_removed'] ? implode(', ', $result['accounts_removed']) : 'none')."; kept (they belong elsewhere): {$result['accounts_kept']}");
        foreach ($result['notes'] as $note) {
            $this->components->warn($note);
        }

        return self::SUCCESS;
    }
}
