<?php

// MULTI_WAREHOUSE.md §7: making a new, empty warehouse end to end against a real schema and database user. Uses the provisioning
// test user from scripts/TESTING.md (skips without it); everything created is dropped again.

use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Services\Warehouses\Provisioner;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\SchemaSnapshot;

beforeEach(function () {
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $this->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $this->markTestSkipped('The provisioning test user (scripts/TESTING.md) does not exist here.');
    }
    config(['warehouses.provisioning' => [
        'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
        'schema_prefix' => 'test_reliefinventory_create'.bin2hex(random_bytes(2)), 'user_host' => 'localhost', 'max_user_connections' => 7,
    ] + config('warehouses.provisioning')]);
});

afterEach(function () {
    app(WarehouseContext::class)->switchTo(Warehouse::default());
    if (isset($this->warehouse)) {
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$this->warehouse->schema_name.'`');
        $this->admin->exec("DROP USER IF EXISTS 'ri_".strtolower($this->warehouse->id)."'@'localhost'");
        File::deleteDirectory($this->warehouse->secretsPath());
        File::deleteDirectory(dirname($this->warehouse->storagePath()));
    }
});

test('a new warehouse is migrated, seeded, closed to /install, and left awaiting its setup code', function () {
    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();

    [$this->warehouse, $code] = $creator->create('Fresh Warehouse', 'boss@example.org', 'test');
    $warehouse = $this->warehouse->fresh();

    expect($warehouse->status)->toBe('awaiting_setup')
        ->and($warehouse->schema_name)->toBe(Provisioner::standardSchemaName($warehouse->id))
        ->and($warehouse->migration_state)->toBe('migrated')
        ->and($warehouse->admin_email)->toBe('boss@example.org')
        ->and($warehouse->hasKey())->toBeTrue()
        ->and(app(SetupCodes::class)->find($warehouse, $code))->not->toBeNull()
        ->and($warehouse->setupUrl())->toEndWith('/w/'.$warehouse->id.'/activate');

    app(WarehouseContext::class)->run($warehouse, function () {
        $firstRun = app(FirstRunService::class);
        // Seeded, no administrator, and the latch is set so the first-run wizard never opens here.
        expect($firstRun->essentialDataSeeded())->toBeTrue()
            ->and($firstRun->administratorExists())->toBeFalse()
            ->and($firstRun->needsSetup())->toBeFalse()
            ->and(DB::table('setup_state')->value('completed_at'))->not->toBeNull();
    });
});

test('a warehouse that stopped part-way is finished by provisioning it again', function () {
    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    $id = Warehouse::newId();
    $this->warehouse = new Warehouse(['schema_name' => Provisioner::standardSchemaName($id), 'display_name' => 'Half Done', 'status' => 'provisioning']);
    $this->warehouse->id = $id;
    $this->warehouse->save();

    $code = $creator->provision($this->warehouse);
    expect($this->warehouse->fresh()->status)->toBe('awaiting_setup')->and($code)->not->toBe('');

    expect(fn () => $creator->provision($this->warehouse->fresh()))->toThrow(RuntimeException::class);
});
