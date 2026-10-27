<?php

use App\Console\Commands\InstanceMigrate;
use App\Models\Control\Warehouse;
use App\Models\User;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// MULTI_WAREHOUSE.md phase 0: the existing database is the default warehouse, and with one warehouse
// nothing behaves differently.

test('the existing database is registered once as the default warehouse', function () {
    $default = Warehouse::default();

    expect($default)->not->toBeNull()
        ->and($default->schema_name)->toBe(config('database.connections.'.config('database.default').'.database'))
        ->and($default->id)->toMatch('/^[2-9ABCDEFGHJKMNPQRSTVWXYZ]{8}$/')
        ->and($default->uuid)->toBeString()
        ->and(InstanceMigrate::registerDefaultWarehouse()->is($default))->toBeTrue()
        ->and(Warehouse::where('is_default', true)->count())->toBe(1);
});

test('every request works in the default warehouse', function () {
    Route::get('/test-current-warehouse', fn (WarehouseContext $context) => $context->current()->id);

    $this->get('/test-current-warehouse')->assertOk()->assertSee(Warehouse::default()->id);
});

test('switching warehouses re-points the connection, key and files, and switching back restores them', function () {
    $context = app(WarehouseContext::class);
    $home = $context->current();
    $state = fn () => [config('database.default'), config('database.connections.'.config('database.default')), Model::currentEncrypter()->getKey(), config('filesystems.disks.local.root')];
    $before = $state();
    $other = new Warehouse(['schema_name' => 'test_reliefinventory_other', 'display_name' => 'Other']);
    $other->id = 'ZZZZZZZZ';
    $other->writeKey($otherKey = 'base64:'.base64_encode(random_bytes(32)));

    try {
        $seen = $context->run($other, fn () => [
            DB::connection()->getDatabaseName(), 'base64:'.base64_encode(Model::currentEncrypter()->getKey()),
            config('filesystems.disks.local.root'), Model::currentEncrypter()->encryptString('secret'),
        ]);
    } finally {
        File::deleteDirectory($other->secretsPath());
    }

    expect($seen[0])->toBe('test_reliefinventory_other')
        ->and($seen[1])->toBe($otherKey)
        ->and($seen[2])->toBe($other->storagePath())
        // Encrypted under the other warehouse's key: unreadable here.
        ->and(fn () => Model::currentEncrypter()->decryptString($seen[3]))->toThrow(DecryptException::class)
        ->and($state())->toBe($before)
        ->and($context->current()->is($home))->toBeTrue();
});

test('a warehouse without a key file cannot be switched to, and nothing is left half-switched', function () {
    $context = app(WarehouseContext::class);
    $state = fn () => [config('database.default'), config('database.connections.'.config('database.default')), Model::currentEncrypter()->getKey(), config('filesystems.disks.local.root')];
    $before = $state();
    $keyless = new Warehouse(['schema_name' => 'test_reliefinventory_keyless', 'display_name' => 'Keyless']);
    $keyless->id = 'YYYYYYYY';

    expect(fn () => $context->run($keyless, fn () => null))->toThrow(RuntimeException::class)
        ->and($state())->toBe($before);
});

test('warehouse data is under the warehouse key; APP_KEY keeps cookies', function () {
    $key = Warehouse::default()->key();
    expect('base64:'.base64_encode(Model::currentEncrypter()->getKey()))->toBe($key)
        ->and($key)->not->toBe(config('app.key'))
        ->and(app('encrypter')->getKey())->toBe(base64_decode(substr(config('app.key'), 7)));

    // A request carrying a cookie encrypted with APP_KEY is read; the session works across requests.
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->get('/profile')->assertOk();
});

test('uploads go to the warehouse\'s own files folder', function () {
    Storage::disk('local')->put('receiving-photos/x.jpg', 'bytes');

    expect(file_get_contents(Warehouse::default()->storagePath('receiving-photos/x.jpg')))->toBe('bytes');
    Storage::disk('local')->delete('receiving-photos/x.jpg');
});
