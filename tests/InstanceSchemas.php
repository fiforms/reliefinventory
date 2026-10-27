<?php

namespace Tests;

use App\Console\Commands\InstanceMigrate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The control and accounts schemas for tests (MULTI_WAREHOUSE.md §13, "Test setup"). They are named after
 * the test database actually in use — so `pest --parallel` workers (test_reliefinventory_test_3) each get
 * their own — created if missing, migrated from scratch once per process, and given the default warehouse.
 * Needs the grant in scripts/TESTING.md.
 */
class InstanceSchemas
{
    private static bool $migrated = false;

    public static function prepare(): void
    {
        $base = config('database.connections.'.config('database.default').'.database');

        foreach (array_keys(InstanceMigrate::INSTANCE_SCHEMAS) as $connection) {
            $name = "{$base}_{$connection}";
            // The same guarantee phpunit.xml gives the main test database: never a real schema.
            if (! str_contains($name, 'test')) {
                throw new RuntimeException("Refusing to use {$name} as a test schema: its name doesn't contain \"test\".");
            }
            config(["database.connections.{$connection}.database" => $name]);
            DB::purge($connection);
        }

        if (self::$migrated) {
            return;
        }

        foreach (InstanceMigrate::INSTANCE_SCHEMAS as $connection => $path) {
            $name = config("database.connections.{$connection}.database");
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$name}`");
            if (is_dir(base_path($path))) {
                Artisan::call('migrate:fresh', ['--database' => $connection, '--path' => $path, '--force' => true]);
            }
        }
        InstanceMigrate::registerDefaultWarehouse();

        self::$migrated = true;
    }
}
