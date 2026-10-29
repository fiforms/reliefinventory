<?php

// MULTI_WAREHOUSE.md §11: a warehouse administrator restoring a backup into their own, set-up warehouse. The engine tests run against a
// real schema and database user (the provisioning test user of dev/TESTING.md; they skip without it) because a restore really
// wipes and reloads the schema. The gate tests use the shared test warehouse.

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Models\Person;
use App\Services\BackupService;
use App\Services\FirstRunService;
use App\Services\RestoreService;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use App\Services\Warehouses\WarehouseSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\SchemaSnapshot;

/** A real, active warehouse with one Administrator (Ada). */
function ownRestoreWarehouse($test): void
{
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $test->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $test->markTestSkipped('The provisioning test user (dev/TESTING.md) does not exist here.');
    }
    $test->tmp = sys_get_temp_dir().'/ri-own-restore-'.bin2hex(random_bytes(4));
    mkdir($test->tmp.'/out', 0777, true);
    config([
        'warehouses.provisioning' => [
            'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
            'schema_prefix' => 'test_reliefinventory_or'.bin2hex(random_bytes(2)), 'user_host' => 'localhost', 'max_user_connections' => 7,
        ] + config('warehouses.provisioning'),
        'backup.dir' => $test->tmp.'/out',
    ]);
    $test->context = app(WarehouseContext::class);

    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    [$test->warehouse] = $creator->create('Own Place', null, 'test');
    $code = SetupCode::where('warehouse_id', $test->warehouse->id)->firstOrFail();
    $test->context->switchTo($test->warehouse);
    $test->ada = app(WarehouseSetup::class)->withNewAccount($test->warehouse, $code, ['email' => 'ada@example.org', 'password' => 'adas-password-1', 'first_name' => 'Ada', 'last_name' => 'Admin']);
    $test->warehouse = $test->warehouse->fresh();
    $test->context->switchTo($test->warehouse);
}

afterEach(function () {
    if (isset($this->warehouse)) {
        $this->context->switchTo(Warehouse::default());
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$this->warehouse->schema_name.'`');
        $this->admin->exec("DROP USER IF EXISTS 'ri_".strtolower($this->warehouse->id)."'@'localhost'");
        File::deleteDirectory($this->warehouse->secretsPath());
        File::deleteDirectory(dirname($this->warehouse->storagePath()));
    }
    isset($this->tmp) && exec('rm -rf '.escapeshellarg($this->tmp));
});

test('a backup records which warehouse it is of', function () {
    ownRestoreWarehouse($this);
    $zip = new ZipArchive;
    $zip->open(app(BackupService::class)->createIn($this->tmp.'/zips', 'database')['path']);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect($manifest['warehouse'])->toBe(['id' => $this->warehouse->id, 'uuid' => $this->warehouse->uuid]);
});

test('an administrator\'s restore replaces the data and files, invites other logins, keeps the restorer Administrator and purges only this warehouse\'s jobs', function () {
    ownRestoreWarehouse($this);
    $bob = new Account(['email' => 'bob@example.org']);
    $bob->forceFill(['password' => Hash::make('bobs-password-1'), 'email_verified_at' => now()])->save();
    Membership::create(['account_id' => $bob->id, 'warehouse_id' => Warehouse::default()->id, 'person_id' => 987654]);

    $this->context->switchTo($this->warehouse);
    $bobPerson = Person::create(['first_name' => 'Bob', 'last_name' => 'Backup', 'email' => 'bob@example.org', 'approved_at' => now()]);
    Membership::create(['account_id' => $bob->id, 'warehouse_id' => $this->warehouse->id, 'person_id' => $bobPerson->id]);
    Storage::disk('local')->put('receiving-photos/old.jpg', 'from-the-backup');
    $zip = app(BackupService::class)->createIn($this->tmp.'/zips', 'full')['path'];

    // After the backup: a newer person, a newer file, queued jobs (ours and another warehouse's).
    Person::create(['first_name' => 'Newer', 'last_name' => 'Person', 'email' => 'newer@example.org', 'approved_at' => now()]);
    Storage::disk('local')->put('receiving-photos/newer.jpg', 'made-after');
    $control = $this->warehouse->getConnection();
    $control->table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{"warehouse":"'.$this->warehouse->id.'"}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
        ['queue' => 'default', 'payload' => '{"warehouse":"ZZZZZZZZ"}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
    ]);

    app(RestoreService::class)->stageOwnRestore(['account_id' => $this->ada->account->id, 'first_name' => 'Ada', 'last_name' => 'Admin', 'any_lineage' => false]);
    $result = app(RestoreService::class)->runFromFiles($zip, null, false);
    $this->context->switchTo($this->warehouse);

    expect($result['database_restored'])->toBeTrue()
        ->and($this->warehouse->fresh()->status)->toBe('active')
        ->and(DB::table('people')->where('email', 'newer@example.org')->exists())->toBeFalse()
        ->and(Storage::disk('local')->get('receiving-photos/old.jpg'))->toBe('from-the-backup')
        ->and(Storage::disk('local')->exists('receiving-photos/newer.jpg'))->toBeFalse()   // replaced, not merged
        ->and($control->table('jobs')->where('payload', 'like', '%'.$this->warehouse->id.'%')->exists())->toBeFalse()
        ->and($control->table('jobs')->where('payload', 'like', '%ZZZZZZZZ%')->count())->toBe(1);
    $control->table('jobs')->where('payload', 'like', '%ZZZZZZZZ%')->delete();

    // Ada: the person from the backup with her email, linked to her login and Administrator. Bob's login is elsewhere, so it was only invited.
    $adaId = DB::table('people')->where('email', 'ada@example.org')->value('id');
    expect(DB::table('people')->where('id', $adaId)->value('account_id'))->toBe($this->ada->account->id)
        ->and(DB::table('people_roles')->where('person_id', $adaId)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
        ->and(Membership::where('account_id', $bob->id)->where('warehouse_id', $this->warehouse->id)->exists())->toBeFalse()
        ->and(MembershipInvitation::where('warehouse_id', $this->warehouse->id)->where('person_id', $bobPerson->id)->where('source', 'restore')->exists())->toBeTrue()
        ->and(Account::where('is_instance_admin', true)->whereKey($bob->id)->exists())->toBeFalse()
        ->and(is_file($this->tmp.'/out/warehouses/'.$this->warehouse->id.'/restore/own.json'))->toBeFalse();
});

test('a rollback to a snapshot takes a safety copy first, keeps the snapshot, replaces the data and keeps the restorer Administrator', function () {
    ownRestoreWarehouse($this);
    $this->context->switchTo($this->warehouse);
    $backups = app(BackupService::class);
    $snapshot = $backups->createIn($backups->dir(), 'database', 'relief-backup-20260101-020000-database-77')['path'];

    Person::create(['first_name' => 'Newer', 'last_name' => 'Person', 'email' => 'newer@example.org', 'approved_at' => now()]);
    app(RestoreService::class)->stageOwnRestore(['account_id' => $this->ada->account->id, 'first_name' => 'Ada', 'last_name' => 'Admin', 'any_lineage' => false]);
    app(RestoreService::class)->runSnapshot(basename($snapshot));
    $this->context->switchTo($this->warehouse);

    $adaId = DB::table('people')->where('email', 'ada@example.org')->value('id');
    expect(app(RestoreService::class)->status()['state'])->toBe('complete')
        ->and(DB::table('people')->where('email', 'newer@example.org')->exists())->toBeFalse()   // rolled back
        ->and(is_file($snapshot))->toBeTrue()                                                    // the snapshot itself is never consumed
        ->and(glob($backups->dir().'/relief-backup-*-database-before-rollback.zip'))->toHaveCount(1)
        ->and(DB::table('people_roles')->where('person_id', $adaId)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
        ->and($this->warehouse->fresh()->status)->toBe('active')
        ->and(is_file($this->tmp.'/out/warehouses/'.$this->warehouse->id.'/restore/own.json'))->toBeFalse();

    // The safety copy holds what was there just before the rollback, so the rollback can itself be undone.
    $safety = glob($backups->dir().'/relief-backup-*-database-before-rollback.zip')[0];
    $zip = new ZipArchive;
    $zip->open($safety);
    expect($zip->getFromName('database/db.sql'))->toContain('newer@example.org');
});

test('a rollback only ever reads a zip in this warehouse\'s own folder, and a missing one changes nothing', function () {
    ownRestoreWarehouse($this);
    $this->context->switchTo($this->warehouse);
    Person::create(['first_name' => 'Keep', 'last_name' => 'Me', 'email' => 'keep@example.org', 'approved_at' => now()]);
    app(RestoreService::class)->stageOwnRestore(['account_id' => $this->ada->account->id, 'first_name' => 'Ada', 'last_name' => 'Admin', 'any_lineage' => false]);

    foreach (['../../outside.zip', 'relief-backup-missing.zip', 'notes.zip'] as $name) {
        app(RestoreService::class)->runSnapshot($name);
        expect(app(RestoreService::class)->status()['state'])->toBe('failed');
    }
    expect(DB::table('people')->where('email', 'keep@example.org')->exists())->toBeTrue()
        ->and($this->warehouse->fresh()->status)->toBe('active');
});

test('a backup of another warehouse is refused for an administrator and accepted for a clone by an instance administrator', function () {
    ownRestoreWarehouse($this);
    $this->context->switchTo($this->warehouse);
    $zip = app(BackupService::class)->createIn($this->tmp.'/zips', 'database')['path'];
    $this->context->current()->forceFill(['uuid' => (string) Str::uuid()])->save();   // now someone else's lineage
    $people = DB::table('people')->count();
    app(RestoreService::class)->stageOwnRestore(['account_id' => $this->ada->account->id, 'first_name' => 'Ada', 'last_name' => 'Admin', 'any_lineage' => false]);
    expect(fn () => app(RestoreService::class)->runFromFiles($zip, null, false))->toThrow(RuntimeException::class, 'different warehouse');
    expect(DB::table('people')->count())->toBe($people)->and($this->warehouse->fresh()->status)->toBe('active');

    app(RestoreService::class)->stageOwnRestore(['account_id' => $this->ada->account->id, 'first_name' => 'Ada', 'last_name' => 'Admin', 'any_lineage' => true]);
    expect(app(RestoreService::class)->runFromFiles($zip, null, false)['database_restored'])->toBeTrue();
});

test('a backup that does not say which warehouse it is of is refused for an administrator', function () {
    ownRestoreWarehouse($this);
    $this->context->switchTo($this->warehouse);
    $path = app(BackupService::class)->createIn($this->tmp.'/zips', 'database')['path'];
    // Strip the lineage the way a pre-uuid backup lacks it.
    $zip = new ZipArchive;
    $zip->open($path);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    unset($manifest['warehouse']);
    $zip->addFromString('manifest.json', json_encode($manifest));
    $accounts = json_decode($zip->getFromName('database/accounts.json'), true);
    unset($accounts['warehouse']);
    $json = json_encode($accounts);
    $zip->addFromString('database/accounts.json', $json);
    // the manifest checksums the accounts entry
    foreach ($manifest['entries'] as &$e) {
        if ($e['path'] === 'database/accounts.json') {
            $e['size'] = strlen($json);
            $e['sha256'] = hash('sha256', $json);
        }
    }
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();

    app(RestoreService::class)->stageOwnRestore(['account_id' => $this->ada->account->id, 'first_name' => 'Ada', 'last_name' => 'Admin', 'any_lineage' => false]);
    expect(fn () => app(RestoreService::class)->runFromFiles($path, null, false))->toThrow(RuntimeException::class, 'does not say which warehouse');
});

test('the restore endpoints need admin-system, a training warehouse has none, and the launch needs the password', function () {
    $this->postJson('/json/warehouse-restore', ['filename' => 'b.zip', 'size' => 10])->assertUnauthorized();
    $this->actingAs(userWithPermissions('manage-backups'))->postJson('/json/warehouse-restore', ['filename' => 'b.zip', 'size' => 10])->assertForbidden();

    $admin = userWithPermissions('admin-system');
    $admin->forceFill(['password' => Hash::make('right-password-1')])->save();
    $id = $this->actingAs($admin)->postJson('/json/warehouse-restore', ['filename' => 'b.zip', 'size' => 4])->assertCreated()->json('upload_id');
    $this->actingAs($admin)->call('PUT', "/json/warehouse-restore/{$id}?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'abcd')->assertOk();

    $this->actingAs($admin)->postJson('/json/warehouse-restore/launch', ['upload_id' => $id, 'password' => 'wrong'])->assertStatus(422);
    $this->actingAs($admin)->postJson('/json/warehouse-restore/launch', ['upload_id' => $id, 'password' => 'right-password-1', 'any_lineage' => true])->assertForbidden();
    expect(is_file(app(RestoreService::class)->dir().'/own.json'))->toBeFalse();

    trainingWarehouse();
    $this->actingAs($admin)->postJson('/json/warehouse-restore', ['filename' => 'b.zip', 'size' => 4])->assertForbidden();
});

test('the progress poll answers for a warehouse being restored, without its logins', function () {
    $warehouse = Warehouse::current();
    app(RestoreService::class)->uploads();   // creates the folder
    file_put_contents(app(RestoreService::class)->dir().'/status.json', json_encode(['state' => 'complete', 'percent' => 100, 'logins' => ['ada@example.org: Administrator'], 'updated_at' => now()->toIso8601String()]));
    $warehouse->forceFill(['status' => 'restoring'])->save();

    $body = $this->getJson("/w/{$warehouse->id}/restore-status")->assertOk()->json();

    expect($body['state'])->toBe('complete')->and($body)->not->toHaveKey('logins');
    $this->getJson('/json/backups')->assertStatus(503);   // everything else is still out of service
});
