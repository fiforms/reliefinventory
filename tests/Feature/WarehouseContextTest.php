<?php

use App\Console\Commands\InstanceMigrate;
use App\Models\Control\Warehouse;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\Route;

// MULTI_WAREHOUSE.md phase 0: the existing database is the default warehouse, and with one warehouse
// nothing behaves differently.

test('the existing database is registered once as the default warehouse', function () {
    $default = Warehouse::default();

    expect($default)->not->toBeNull()
        ->and($default->schema_name)->toBe(config('database.connections.mariadb.database'))
        ->and($default->id)->toMatch('/^[2-9ABCDEFGHJKMNPQRSTVWXYZ]{8}$/')
        ->and($default->uuid)->toBeString()
        ->and(InstanceMigrate::registerDefaultWarehouse()->is($default))->toBeTrue()
        ->and(Warehouse::where('is_default', true)->count())->toBe(1);
});

test('every request works in the default warehouse', function () {
    Route::get('/test-current-warehouse', fn (WarehouseContext $context) => $context->current()->id);

    $this->get('/test-current-warehouse')->assertOk()->assertSee(Warehouse::default()->id);
});

test('switching warehouses re-points the default connection and switching back restores it', function () {
    $context = app(WarehouseContext::class);
    $base = config('database.connections.mariadb');
    $other = new Warehouse(['schema_name' => 'test_reliefinventory_other', 'display_name' => 'Other']);
    $other->id = 'ZZZZZZZZ';

    $seen = $context->run($other, fn () => config('database.connections.mariadb.database'));

    expect($seen)->toBe('test_reliefinventory_other')
        ->and(config('database.connections.mariadb'))->toBe($base)
        ->and($context->has())->toBeFalse();
});
