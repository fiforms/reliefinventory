<?php

namespace Tests;

use App\Models\Control\Warehouse;
use App\Models\SetupState;
use App\Services\Warehouses\SwitchDatabase;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A second, real warehouse for tests that need more than one (MULTI_WAREHOUSE.md §13, "Test setup"): its own schema
 * (`<test db>_w2`, migrated once per process from the schema snapshot), registered in the control schema inside the
 * test's transaction, and its own connection wrapped in a transaction that is rolled back when the test ends — so,
 * like the default warehouse, nothing a test does there outlives it.
 *
 *     $other = SecondWarehouse::make($this);
 */
class SecondWarehouse
{
    private static bool $migrated = false;

    public static function make(TestCase $test, array $attributes = []): Warehouse
    {
        $schema = self::schema();
        $warehouse = new Warehouse($attributes + ['schema_name' => $schema, 'display_name' => 'Second Warehouse', 'status' => 'active']);
        $warehouse->save();
        $warehouse->writeKey('base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))));
        File::ensureDirectoryExists($warehouse->storagePath());

        $context = app(WarehouseContext::class);
        $context->run($warehouse, function () use ($test, $warehouse) {
            $connection = DB::connection(SwitchDatabase::connectionName($warehouse));
            $connection->beginTransaction();
            $cleanUp = function () use ($connection, $warehouse) {
                $connection->rollBack();
                File::deleteDirectory($warehouse->secretsPath());
                File::deleteDirectory(dirname($warehouse->storagePath()));
            };
            (fn () => $this->beforeApplicationDestroyed($cleanUp))->call($test);
            SetupState::current()->update(['completed_at' => now()]);
        });

        return $warehouse;
    }

    private static function schema(): string
    {
        $base = config('database.connections.'.config('database.default'));
        $schema = $base['database'].'_w2';
        if (! str_contains($schema, 'test')) {
            throw new RuntimeException("Refusing to use {$schema} as a test schema: its name doesn't contain \"test\".");
        }

        if (! self::$migrated) {
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$schema}`");
            config(['database.connections.test_second_warehouse' => ['database' => $schema] + $base]);
            Artisan::call('migrate:fresh', ['--database' => 'test_second_warehouse'] + SchemaSnapshot::freshOptions());
            DB::purge('test_second_warehouse');
            self::$migrated = true;
        }

        return $schema;
    }
}
