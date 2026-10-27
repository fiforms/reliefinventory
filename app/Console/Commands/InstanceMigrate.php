<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Console\Commands;

use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Console\Command;

/**
 * Migrates the whole instance: the control and accounts schemas first, then every warehouse schema
 * (MULTI_WAREHOUSE.md §10). Registers the existing database as the default warehouse the first time.
 * Use this instead of a plain `migrate`, which only migrates the default warehouse's schema.
 */
class InstanceMigrate extends Command
{
    protected $signature = 'instance:migrate {--force : Run in production without asking}';

    protected $description = 'Migrate the control and accounts schemas, then every warehouse.';

    /** Instance-level schemas, in the order they are migrated: connection => migrations directory. */
    public const INSTANCE_SCHEMAS = [
        'control' => 'database/migrations/control',
        'accounts' => 'database/migrations/accounts',
    ];

    public function handle(WarehouseContext $context): int
    {
        foreach (self::INSTANCE_SCHEMAS as $connection => $path) {
            if (! is_dir(base_path($path))) {
                continue;
            }
            $this->components->info("Migrating the {$connection} schema");
            if ($this->call('migrate', ['--database' => $connection, '--path' => $path, '--force' => $this->option('force')]) !== 0) {
                $this->components->error("The {$connection} schema failed to migrate; no warehouse was migrated.");

                return self::FAILURE;
            }
        }

        self::registerDefaultWarehouse();

        $failed = 0;
        foreach (Warehouse::orderByDesc('is_default')->orderBy('created_at')->get() as $warehouse) {
            $this->components->info("Migrating warehouse {$warehouse->id} ({$warehouse->display_name}, schema {$warehouse->schema_name})");
            $code = $context->run($warehouse, fn () => $this->call('migrate', ['--force' => $this->option('force')]));
            $warehouse->forceFill(['migration_state' => $code === 0 ? 'migrated' : 'failed'])->save();
            $failed += $code === 0 ? 0 : 1;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The schema the instance has always used becomes the default warehouse, keeping its name. Idempotent.
     */
    public static function registerDefaultWarehouse(): Warehouse
    {
        if ($existing = Warehouse::default()) {
            return $existing;
        }

        $warehouse = new Warehouse([
            'schema_name' => config('database.connections.'.config('database.default').'.database'),
            'display_name' => config('app.name') ?: 'Relief Inventory',
            'status' => 'active',
        ]);
        $warehouse->is_default = true;
        $warehouse->save();

        return $warehouse;
    }
}
