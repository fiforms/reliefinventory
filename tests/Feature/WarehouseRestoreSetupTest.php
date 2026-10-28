<?php

// MULTI_WAREHOUSE.md §7/§11: a backup restored into a warehouse awaiting its setup code, against a real schema and database user
// (needs the provisioning test user of scripts/TESTING.md; skips without it). Restoring really wipes and reloads that warehouse's
// schema, which is why it can't use the shared wrapped test warehouse.

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Models\Person;
use App\Services\BackupCompatibility;
use App\Services\BackupService;
use App\Services\FirstRunService;
use App\Services\RestoreService;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\SchemaSnapshot;

beforeEach(function () {
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $this->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $this->markTestSkipped('The provisioning test user (scripts/TESTING.md) does not exist here.');
    }
    $this->tmp = sys_get_temp_dir().'/ri-restore-setup-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/out', 0777, true);
    config([
        'warehouses.provisioning' => [
            'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
            'schema_prefix' => 'test_reliefinventory_rs'.bin2hex(random_bytes(2)), 'user_host' => 'localhost', 'max_user_connections' => 7,
        ] + config('warehouses.provisioning'),
        'backup.dir' => $this->tmp.'/out',
        'backup.upload_chunk_bytes' => 50_000,
    ]);
    $this->context = app(WarehouseContext::class);

    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    [$this->warehouse, $this->code] = $creator->create('Restored Place', null, 'test');
    $this->codeRow = SetupCode::where('warehouse_id', $this->warehouse->id)->firstOrFail();

    $sam = new Account(['email' => 'sam@example.org']);
    $sam->forceFill(['password' => Hash::make('sams-password-1'), 'email_verified_at' => now()])->save();
    $this->sam = $sam;
    $this->identity = ['login' => 'existing', 'first_name' => 'Sam', 'last_name' => 'Holder', 'email' => 'sam@example.org', 'password' => 'sams-password-1'];

    // A browser on the new warehouse's setup screen, having typed the code.
    $this->asCodeHolder = fn () => $this->withSession(['setup_claim' => $this->codeRow->id])->withHeader('X-RI-Warehouse', $this->warehouse->id);
    $this->send = fn (string $method, string $uri, string $content = '', array $json = []) => ($this->asCodeHolder)()->call(
        $method, $uri, $json, ['ri_warehouse' => $this->warehouse->id], [], ['HTTP_X_RI_WAREHOUSE' => $this->warehouse->id, 'CONTENT_TYPE' => $content !== '' ? 'application/octet-stream' : 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content !== '' ? $content : ($json ? json_encode($json) : null),
    );
});

afterEach(function () {
    $this->context->switchTo(Warehouse::default());
    if (isset($this->warehouse)) {
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$this->warehouse->schema_name.'`');
        $this->admin->exec("DROP USER IF EXISTS 'ri_".strtolower($this->warehouse->id)."'@'localhost'");
        File::deleteDirectory($this->warehouse->secretsPath());
        File::deleteDirectory(dirname($this->warehouse->storagePath()));
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
});

/** Upload a zip through the awaiting-setup endpoints and launch the restore; returns the launch response. */
function uploadAndLaunch(string $zip, array $identity)
{
    $size = filesize($zip);
    $id = (test()->send)('POST', '/activate/restore', '', ['filename' => 'b.zip', 'size' => $size])->assertCreated()->json('upload_id');
    $bytes = file_get_contents($zip);
    for ($offset = 0; $offset < $size; $offset += 50_000) {
        (test()->send)('PUT', "/activate/restore/{$id}?offset={$offset}", substr($bytes, $offset, 50_000))->assertOk();
    }

    return [$id, (test()->send)('POST', '/activate/restore/launch', '', ['upload_id' => $id] + $identity)];
}

test('a restore into a warehouse awaiting setup brings the data, invites the backup\'s existing logins and makes the code holder Administrator', function () {
    $old = new Account(['email' => 'old@example.org']);
    $old->forceFill(['password' => Hash::make('olds-password-1'), 'email_verified_at' => now()])->save();

    // The backup: a warehouse with its old administrator and a person who is the code holder (same email), made in the new
    // warehouse's own schema, dumped, then emptied again as a brand-new warehouse is.
    $zip = $this->context->run($this->warehouse, function () use ($old) {
        $oldAdmin = Person::create(['first_name' => 'Old', 'last_name' => 'Admin', 'email' => 'old@example.org', 'approved_at' => now()]);
        $samBefore = Person::create(['first_name' => 'Sam', 'last_name' => 'Backup', 'email' => 'sam@example.org', 'approved_at' => now()]);
        DB::table('people_roles')->insert(['person_id' => $oldAdmin->id, 'role_id' => FirstRunService::ADMINISTRATOR_ROLE_ID, 'created_at' => now(), 'updated_at' => now()]);
        Membership::create(['account_id' => $old->id, 'warehouse_id' => $this->warehouse->id, 'person_id' => $oldAdmin->id]);
        $path = app(BackupService::class)->createIn($this->tmp.'/zips', 'database')['path'];
        $this->ids = [$oldAdmin->id, $samBefore->id];
        Membership::where('warehouse_id', $this->warehouse->id)->delete();
        DB::table('people_roles')->delete();
        DB::table('people')->whereIn('id', $this->ids)->delete();

        return $path;
    });
    // The restore must not reach any login that lives anywhere else: Old Admin's account belongs to another warehouse.
    Membership::create(['account_id' => $old->id, 'warehouse_id' => Warehouse::default()->id, 'person_id' => 12345]);

    Process::fake();
    [$id, $launch] = uploadAndLaunch($zip, $this->identity);
    $launch->assertStatus(202);
    $this->context->switchTo($this->warehouse);
    app(RestoreService::class)->run($id);
    $status = app(RestoreService::class)->status();

    expect($status['state'])->toBe('complete', $status['error'] ?? '')
        ->and($this->warehouse->fresh()->status)->toBe('active')
        ->and($this->codeRow->fresh()->used_at)->not->toBeNull();

    $this->context->run($this->warehouse, function () {
        [$oldId, $samId] = $this->ids;
        // The data is back, and the code holder is the person the backup had under their email, now Administrator.
        expect(DB::table('people')->where('email', 'old@example.org')->exists())->toBeTrue()
            ->and(DB::table('people')->where('email', 'sam@example.org')->value('id'))->toBe($samId)
            ->and(DB::table('people')->where('id', $samId)->value('account_id'))->toBe($this->sam->id)
            ->and(DB::table('people_roles')->where('person_id', $samId)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
            ->and(DB::table('people')->where('id', $oldId)->value('account_id'))->toBeNull();
    });

    // Old Admin's login exists elsewhere, so it was only invited; nothing about it changed. Nobody became an instance admin.
    expect(Membership::where('account_id', $old->id)->where('warehouse_id', $this->warehouse->id)->exists())->toBeFalse()
        ->and(MembershipInvitation::where('warehouse_id', $this->warehouse->id)->where('person_id', $this->ids[0])->where('source', 'restore')->exists())->toBeTrue()
        ->and(Hash::check('olds-password-1', $old->fresh()->password))->toBeTrue()
        ->and(Account::where('is_instance_admin', true)->whereIn('id', [$old->id, $this->sam->id])->exists())->toBeFalse()
        ->and(Membership::where('account_id', $this->sam->id)->where('warehouse_id', $this->warehouse->id)->exists())->toBeTrue();

    // The restore folder is this warehouse's own, and the claim is gone.
    expect(is_dir($this->tmp.'/out/warehouses/'.$this->warehouse->id.'/restore'))->toBeTrue()
        ->and(is_file($this->tmp.'/out/warehouses/'.$this->warehouse->id.'/restore/claim.json'))->toBeFalse()
        ->and(file_exists(storage_path('framework/down')))->toBeFalse();
});

test('a restore that fails returns the warehouse to its empty, ready-to-set-up state and the code still works', function () {
    $dump = "THIS IS NOT SQL;\n-- Dump completed on 2026-10-20 00:00:00\n";
    $zip = $this->tmp.'/bad.zip';
    $manifest = [
        'format' => 1, 'created_at' => now()->toIso8601String(), 'app' => ['commit' => 'abc'],
        'database' => ['migrations' => app(BackupCompatibility::class)->knownMigrations()], 'includes_uploads' => false, 'contains_app_key' => false,
        'scope' => 'database', 'entries' => [['path' => 'database/db.sql', 'size' => strlen($dump), 'sha256' => hash('sha256', $dump)]],
    ];
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::CREATE);
    $archive->addFromString('database/db.sql', $dump);
    $archive->addFromString('manifest.json', json_encode($manifest));
    $archive->close();

    Process::fake();
    [$id, $launch] = uploadAndLaunch($zip, $this->identity);
    $launch->assertStatus(202);
    $this->context->switchTo($this->warehouse);
    app(RestoreService::class)->run($id);
    $status = app(RestoreService::class)->status();

    expect($status['state'])->toBe('failed')->and($status['error'])->toContain('ready-to-set-up')
        ->and($this->warehouse->fresh()->status)->toBe('awaiting_setup')
        ->and(SetupCode::usable()->whereKey($this->codeRow->id)->exists())->toBeTrue();
    $this->context->run($this->warehouse, function () {
        $firstRun = app(FirstRunService::class);
        expect($firstRun->essentialDataSeeded())->toBeTrue()->and($firstRun->administratorExists())->toBeFalse()->and($firstRun->needsSetup())->toBeFalse();
    });
    expect(Account::where('email', 'sam@example.org')->exists())->toBeTrue()->and(Membership::where('warehouse_id', $this->warehouse->id)->exists())->toBeFalse();
});

test('only the holder of the code can use the restore endpoints, and a wrong password stops the launch', function () {
    $this->withHeader('X-RI-Warehouse', $this->warehouse->id)->call('POST', '/activate/restore', ['filename' => 'b.zip', 'size' => 10], ['ri_warehouse' => $this->warehouse->id], [], ['HTTP_X_RI_WAREHOUSE' => $this->warehouse->id, 'HTTP_ACCEPT' => 'application/json'])->assertForbidden();

    $zip = $this->tmp.'/x.zip';
    file_put_contents($zip, 'not a backup');
    $id = ($this->send)('POST', '/activate/restore', '', ['filename' => 'b.zip', 'size' => filesize($zip)])->assertCreated()->json('upload_id');
    ($this->send)('PUT', "/activate/restore/{$id}?offset=0", 'not a backup')->assertOk();
    ($this->send)('POST', '/activate/restore/launch', '', ['upload_id' => $id, 'password' => 'wrong'] + $this->identity)->assertStatus(422);

    expect(is_file($this->tmp.'/out/warehouses/'.$this->warehouse->id.'/restore/claim.json'))->toBeFalse();
});
