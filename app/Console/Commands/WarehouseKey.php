<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;

/**
 * Show or import a warehouse's key (MULTI_WAREHOUSE.md §5). A backup never carries it, so moving a warehouse's data to a new instance keeps
 * its encrypted settings only if the old key is given to the new warehouse *before* the restore: `warehouse:key import <id>`, then restore.
 * Importing replaces the key, so it is refused unless the warehouse holds nothing yet (never set up, or awaiting its setup code), or --force.
 * An instance admin can already read the key file, so showing it is no new power; both are written to the instance audit log.
 */
class WarehouseKey extends Command
{
    protected $signature = 'warehouse:key
        {action : show | import}
        {warehouse : the warehouse id}
        {--file= : import: a file holding the key (otherwise asked for)}
        {--force : import: replace the key of a warehouse that is already set up (its encrypted settings become unreadable)}';

    protected $description = "Show a warehouse's key, or import the old one before restoring its backup on a new instance.";

    public function handle(WarehouseContext $context): int
    {
        $warehouse = Warehouse::find(strtoupper((string) $this->argument('warehouse')));
        if (! $warehouse) {
            $this->components->error("There is no warehouse {$this->argument('warehouse')}. `php artisan warehouse:list` shows them.");

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'show' => $this->show($warehouse),
            'import' => $this->import($warehouse, $context),
            default => $this->refuse('The action is "show" or "import".'),
        };
    }

    private function show(Warehouse $warehouse): int
    {
        if (! $warehouse->hasKey()) {
            return $this->refuse("{$warehouse->display_name} has no key file.");
        }
        InstanceAuditLog::record('warehouse.key_revealed', $warehouse);
        $this->line($warehouse->key());

        return self::SUCCESS;
    }

    private function import(Warehouse $warehouse, WarehouseContext $context): int
    {
        if ($file = $this->option('file')) {
            $key = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        } elseif ($this->input->isInteractive()) {
            $key = trim((string) $this->secret('The old warehouse key (base64:…)'));
        } else {
            return $this->refuse('Give the key with --file, or run this in a terminal.');
        }
        if (! Warehouse::validKey($key)) {
            return $this->refuse('That is not a warehouse key (it starts with "base64:" and is 51 characters in all).');
        }

        if ($warehouse->hasKey() && ! $this->option('force') && ! $this->isEmpty($warehouse, $context)) {
            return $this->refuse("{$warehouse->display_name} is already set up: replacing its key would make the settings encrypted under the current one unreadable. Add --force if that is what you want.");
        }

        $warehouse->replaceKey($key);
        InstanceAuditLog::record('warehouse.key_imported', $warehouse, ['forced' => (bool) $this->option('force')]);
        $this->components->info("{$warehouse->display_name} now uses the key you gave. Restore its backup next; the web containers read the key on each request, so no restart is needed.");

        return self::SUCCESS;
    }

    /** Nothing in it yet: waiting for its setup code, or never set up (the first-run wizard hasn't run). */
    private function isEmpty(Warehouse $warehouse, WarehouseContext $context): bool
    {
        if ($warehouse->isAwaitingSetup()) {
            return true;
        }

        return $context->run($warehouse, fn () => rescue(fn () => app(FirstRunService::class)->needsSetup(), false, false));
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
