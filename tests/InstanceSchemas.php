<?php

namespace Tests;

use App\Console\Commands\InstanceMigrate;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The control and accounts schemas for tests (MULTI_WAREHOUSE.md §13, "Test setup"). They are named after
 * the test database actually in use — so `pest --parallel` workers (test_reliefinventory_test_3) each get
 * their own — created if missing, migrated from scratch once per process, and given the default warehouse.
 * Needs the grant in dev/TESTING.md.
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

        if (! self::$migrated) {
            self::migrate();
        }

        // As a console command would be (WarehouseContext::bootConsole(), which is skipped in tests because it runs
        // before a parallel worker's databases are chosen): in the default warehouse, with its key and files.
        app(WarehouseContext::class)->switchTo(Warehouse::default());
    }

    private static function migrate(): void
    {
        // Each process registers a new test warehouse (with a new id), so its key and files folder are new too.

        foreach (InstanceMigrate::INSTANCE_SCHEMAS as $connection => $path) {
            $name = config("database.connections.{$connection}.database");
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$name}`");
            if (is_dir(base_path($path))) {
                Artisan::call('migrate:fresh', ['--database' => $connection, '--path' => $path, '--force' => true]);
            }
        }
        $warehouse = InstanceMigrate::registerDefaultWarehouse();
        // A key of its own, unlike APP_KEY, as on a Docker instance: anything still signing or encrypting with the
        // instance key where it should use the warehouse's (or the other way round) fails a test.
        $warehouse->writeKey('base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))));
        File::ensureDirectoryExists($warehouse->storagePath());
        self::pruneOldTestWarehouses();

        self::$migrated = true;
    }

    /** Each run leaves a key and a files folder per worker; keep the last day's. */
    private static function pruneOldTestWarehouses(): void
    {
        foreach ([config('warehouses.storage_dir'), config('warehouses.secrets_dir')] as $dir) {
            foreach (File::directories($dir) as $old) {
                if (filemtime($old) < time() - 86400) {
                    File::deleteDirectory($old);
                }
            }
        }
    }
}
