<?php

// MULTI_WAREHOUSE.md §11: the whole-instance backup and restore, against real schemas and the provisioning test user of
// dev/TESTING.md (skips without it). The restore test points the control and accounts connections at schemas of its own
// first, because a restore drops what it finds.

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceRecoveryKey;
use App\Models\Control\Warehouse;
use App\Services\BackupCompatibility;
use App\Services\BackupService;
use App\Services\InstanceBackupService;
use App\Services\InstanceRecoveryKeys;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use App\Support\RecoveryKey;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\SchemaSnapshot;

beforeEach(function () {
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $this->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $this->markTestSkipped('The provisioning test user (dev/TESTING.md) does not exist here.');
    }
    $this->tmp = sys_get_temp_dir().'/ri-instance-backup-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/out', 0777, true);
    $this->originalConnections = [config('database.connections.control.database'), config('database.connections.accounts.database')];
    config([
        'warehouses.provisioning' => [
            'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
            'schema_prefix' => 'test_reliefinventory_ib'.bin2hex(random_bytes(2)), 'user_host' => 'localhost', 'max_user_connections' => 7,
        ] + config('warehouses.provisioning'),
        'backup.dir' => $this->tmp.'/out',
    ]);
    $this->created = [];
    InstanceRecoveryKey::query()->delete();
});

afterEach(function () {
    [$control, $accounts] = $this->originalConnections;
    config(['database.connections.control.database' => $control, 'database.connections.accounts.database' => $accounts]);
    DB::purge('control');
    DB::purge('accounts');
    app(WarehouseContext::class)->switchTo(Warehouse::default());
    foreach ($this->created as $warehouse) {
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$warehouse['schema'].'`');
        $this->admin->exec("DROP USER IF EXISTS 'ri_".strtolower($warehouse['id'])."'@'localhost'");
        File::deleteDirectory(config('warehouses.secrets_dir').'/'.$warehouse['id']);
        File::deleteDirectory(config('warehouses.storage_dir').'/'.$warehouse['id']);
    }
    foreach ($this->extraSchemas ?? [] as $schema) {
        $this->admin->exec("DROP DATABASE IF EXISTS `{$schema}`");
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
});

test('an instance backup is one zip of every schema, with the keys encrypted and no keys without a passphrase', function () {
    $warehouse = Warehouse::default();
    File::ensureDirectoryExists($warehouse->storagePath('receiving-photos'));
    file_put_contents($warehouse->storagePath('receiving-photos/p.jpg'), 'a-photo');

    $result = app(InstanceBackupService::class)->create($this->tmp.'/zips', 'unit', true, 'a long passphrase');
    $zip = new ZipArchive;
    $zip->open($result['path']);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));

    expect($manifest['kind'])->toBe('instance')->and($manifest['includes_keys'])->toBeTrue()->and($manifest['contains_app_key'])->toBeFalse()
        ->and($manifest['schemas'])->toContain(config('database.connections.control.database'), config('database.connections.accounts.database'), $warehouse->schema_name)
        ->and($names)->toContain('instance/dump.sql', 'instance/instance.json', "files/{$warehouse->id}/receiving-photos/p.jpg", "keys/{$warehouse->id}/warehouse.key");

    $dump = $zip->getFromName('instance/dump.sql');
    expect($dump)->toContain('CREATE DATABASE')->toContain('-- Dump completed')
        ->and($zip->getFromName("keys/{$warehouse->id}/warehouse.key"))->toBeFalse();    // encrypted: unreadable without the passphrase
    $zip->setPassword('a long passphrase');
    expect($zip->getFromName("keys/{$warehouse->id}/warehouse.key"))->toBe(trim(file_get_contents($warehouse->secretsPath('warehouse.key'))));

    // Live state stays out: the structure of the queue tables is there, the rows are not.
    DB::connection('control')->table('jobs')->insert(['queue' => 'default', 'payload' => 'SECRET-JOB-PAYLOAD', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    $again = app(InstanceBackupService::class)->create($this->tmp.'/zips', 'again', false, null);
    $zip2 = new ZipArchive;
    $zip2->open($again['path']);
    expect($zip2->getFromName('instance/dump.sql'))->toContain('CREATE TABLE `jobs`')->not->toContain('SECRET-JOB-PAYLOAD');
    DB::connection('control')->table('jobs')->where('payload', 'SECRET-JOB-PAYLOAD')->delete();
    $m2 = json_decode($zip2->getFromName('manifest.json'), true);
    expect($m2['includes_keys'])->toBeFalse()->and(collect($m2['entries'])->pluck('path')->filter(fn ($p) => str_starts_with($p, 'keys/') || str_starts_with($p, 'files/')))->toBeEmpty();

    // Each kind refuses the other.
    expect(app(BackupCompatibility::class)->problems($m2, ['manifest.json']))->not->toBeEmpty()
        ->and(fn () => app(InstanceBackupService::class)->restore($result['path'].'.nope', null, false))->toThrow(RuntimeException::class);
});

test('an instance zip is refused by a warehouse restore and a warehouse zip by an instance restore', function () {
    $zip = app(BackupService::class)->createIn($this->tmp.'/zips', 'database')['path'];

    expect(fn () => app(InstanceBackupService::class)->restore($zip, null, false))->toThrow(RuntimeException::class, 'warehouse backup, not a whole-instance');
});

/** Disaster: every warehouse, its user, key and files gone, and the control and accounts schemas empty again (a new instance's). */
function wipeInstance($test, array $warehouses): void
{
    foreach ($warehouses as $w) {
        $test->admin->exec('DROP DATABASE `'.$w->schema_name.'`');
        $test->admin->exec("DROP USER 'ri_".strtolower($w->id)."'@'localhost'");
        File::deleteDirectory($w->secretsPath());
        File::deleteDirectory(dirname($w->storagePath()));
    }
    foreach (['control', 'accounts'] as $connection) {
        $name = config("database.connections.{$connection}.database");
        $test->admin->exec("DROP DATABASE `{$name}`");
        $test->admin->exec("CREATE DATABASE `{$name}`");   // a new instance starts with empty schemas (the provisioner makes them)
    }
    DB::purge('control');
    DB::purge('accounts');
}

/** An instance of its own (new control and accounts schemas, migrated) with two warehouses, a login, a file and a marker person. @return array{0: Warehouse, 1: Warehouse, 2: string} */
function isolatedInstance($test): array
{
    $suffix = bin2hex(random_bytes(2));
    $names = ['control' => "test_reliefinventory_ic{$suffix}_control", 'accounts' => "test_reliefinventory_ic{$suffix}_accounts"];
    $test->extraSchemas = array_values($names);
    foreach ($names as $connection => $name) {
        $test->admin->exec("CREATE DATABASE `{$name}`");
        config(["database.connections.{$connection}.database" => $name]);
        DB::purge($connection);
        Artisan::call('migrate:fresh', ['--database' => $connection, '--path' => "database/migrations/{$connection}", '--force' => true]);
    }

    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    [$one] = $creator->create('Restored One', null, 'test');
    [$two] = $creator->create('Restored Two', null, 'test');
    foreach ([$one, $two] as $w) {
        $test->created[] = ['id' => $w->id, 'schema' => $w->schema_name];
    }
    $account = new Account(['email' => 'kept@example.org']);
    $account->forceFill(['password' => Hash::make('kept-password-1'), 'email_verified_at' => now()])->save();
    $oneKey = trim(file_get_contents($one->secretsPath('warehouse.key')));
    File::ensureDirectoryExists($one->storagePath('receiving-photos'));
    file_put_contents($one->storagePath('receiving-photos/p.jpg'), 'one-photo');
    app(WarehouseContext::class)->run($two, fn () => DB::table('people')->insert(['first_name' => 'Marker', 'last_name' => 'Person', 'email' => 'marker@example.org', 'created_at' => now(), 'updated_at' => now()]));

    return [$one, $two, $oneKey];
}

test('a whole instance is backed up, wiped and restored, with its warehouses, logins, files, keys and database users', function () {
    [$one, $two, $oneKey] = isolatedInstance($this);
    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, true, 'a long passphrase')['path'];

    wipeInstance($this, [$one, $two]);

    expect(fn () => app(InstanceBackupService::class)->restore($zip, 'wrong passphrase!', false))->toThrow(RuntimeException::class, 'passphrase');
    expect(fn () => app(InstanceBackupService::class)->restore($zip, null, false))->toThrow(RuntimeException::class, 'passphrase');

    $result = app(InstanceBackupService::class)->restore($zip, 'a long passphrase', false);

    expect($result)->toMatchArray(['warehouses' => 2, 'files' => true, 'keys' => true]);
    $one = Warehouse::find($one->id);
    $two = Warehouse::find($two->id);
    expect($one->display_name)->toBe('Restored One')
        ->and(trim(file_get_contents($one->secretsPath('warehouse.key'))))->toBe($oneKey)
        ->and(file_get_contents($one->storagePath('receiving-photos/p.jpg')))->toBe('one-photo')
        ->and(is_readable($one->secretsPath('db_password')))->toBeTrue()          // a database user was made again
        ->and(Account::findByEmail('kept@example.org'))->not->toBeNull()
        ->and(InstanceAuditLog::where('action', 'instance.restored')->exists())->toBeTrue();
    app(WarehouseContext::class)->run($two, fn () => expect(DB::table('people')->where('email', 'marker@example.org')->exists())->toBeTrue());   // through its own user

    // A second restore onto an instance now in use needs --force.
    expect(fn () => app(InstanceBackupService::class)->restore($zip, 'a long passphrase', false))->toThrow(RuntimeException::class, '--force');
});

test('a backup made without keys is restored with the keys from a folder laid out like the old warehouse-secrets volume', function () {
    [$one, $two, $oneKey] = isolatedInstance($this);
    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, false, null)['path'];
    foreach ([$one, $two] as $w) {
        File::ensureDirectoryExists($this->tmp."/keys/{$w->id}");
        copy($w->secretsPath('warehouse.key'), $this->tmp."/keys/{$w->id}/warehouse.key");
    }
    wipeInstance($this, [$one, $two]);

    $withoutKeys = app(InstanceBackupService::class)->restore($zip, null, false);
    expect($withoutKeys['keys'])->toBeFalse()->and($withoutKeys['notes'])->not->toBeEmpty()->and(Warehouse::find($one->id)->hasKey())->toBeFalse();

    wipeInstance($this, [Warehouse::find($one->id), Warehouse::find($two->id)]);
    $result = app(InstanceBackupService::class)->restore($zip, null, false, null, $this->tmp.'/keys');

    expect($result['keys'])->toBeTrue()->and($result['notes'])->toBeEmpty()
        ->and(trim(file_get_contents(Warehouse::find($one->id)->secretsPath('warehouse.key'))))->toBe($oneKey);
});

test('every instance backup, with no passphrase, carries the warehouse keys sealed to the recovery key, and says which key', function () {
    [$one, $two, $oneKey] = isolatedInstance($this);
    $pair = app(InstanceRecoveryKeys::class)->generate('admin@example.org');

    $result = app(InstanceBackupService::class)->create($this->tmp.'/zips', 'scheduled-daily', false, null);   // what the provisioner does unattended
    $zip = new ZipArchive;
    $zip->open($result['path']);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));

    expect($names)->toContain('keys/sealed.bin')
        ->and($names->filter(fn ($n) => preg_match('#^keys/[A-Za-z0-9]{8}/#', $n))->all())->toBe([])   // no passphrase entries, no plain keys
        ->and($manifest['keys_sealed'])->toBeTrue()->and($manifest['includes_keys'])->toBeFalse()
        ->and($manifest['contains_warehouse_keys'])->toBeTrue()->and($manifest['recovery_key'])->toBe(['fingerprint' => $pair['fingerprint']])
        ->and($manifest['contains_app_key'])->toBeFalse();

    // sealed: neither warehouse key, nor the private key, is readable anywhere in the zip file
    $raw = file_get_contents($result['path']);
    $twoKey = trim(file_get_contents($two->secretsPath('warehouse.key')));
    expect($raw)->not->toContain($oneKey)->not->toContain($twoKey)->not->toContain(substr($oneKey, 7, 20))
        ->and(app(InstanceBackupService::class)->inspect($result['path']))->toBe(['sealed' => true, 'fingerprint' => $pair['fingerprint'], 'passphrase' => false]);
    $payload = json_decode(RecoveryKey::open($zip->getFromName('keys/sealed.bin'), RecoveryKey::parse($pair['private'])), true);
    expect($payload['keys'])->toMatchArray([$one->id => $oneKey, $two->id => $twoKey]);
});

test('without a recovery key a backup has no sealed entry, and says so in the answer to the provisioner', function () {
    [$one, $two] = isolatedInstance($this);

    $result = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, false, null);
    $zip = new ZipArchive;
    $zip->open($result['path']);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect($zip->getFromName('keys/sealed.bin'))->toBeFalse()->and($manifest['keys_sealed'])->toBeFalse()->and($manifest['recovery_key'])->toBeNull()
        ->and(app(InstanceBackupService::class)->inspect($result['path'])['sealed'])->toBeFalse();

    $lines = app(InstanceBackupService::class)->manual(false);
    expect(implode("\n", $lines))->toContain('no recovery key');
    app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    expect(implode("\n", app(InstanceBackupService::class)->manual(false)))->not->toContain('no recovery key');
});

test('a disaster restore from an automatic zip needs only the printed recovery key, and brings every warehouse key back', function () {
    [$one, $two, $oneKey] = isolatedInstance($this);
    $twoKey = trim(file_get_contents($two->secretsPath('warehouse.key')));
    $pair = app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', 'scheduled-daily', true, null)['path'];
    $file = $this->tmp.'/recovery-key.txt';
    file_put_contents($file, "RELIEF INVENTORY — RECOVERY KEY\n\nRecovery key:\n  {$pair['private']}\n\nKeep it safe.\n");   // the downloaded file, as it is

    wipeInstance($this, [$one, $two]);
    $result = app(InstanceBackupService::class)->restore($zip, null, false, null, null, file_get_contents($file));

    $one = Warehouse::find($one->id);
    $two = Warehouse::find($two->id);
    expect($result)->toMatchArray(['warehouses' => 2, 'files' => true, 'keys' => true])->and($result['notes'])->toBeEmpty()
        ->and(trim(file_get_contents($one->secretsPath('warehouse.key'))))->toBe($oneKey)
        ->and(trim(file_get_contents($two->secretsPath('warehouse.key'))))->toBe($twoKey)
        ->and(file_get_contents($one->storagePath('receiving-photos/p.jpg')))->toBe('one-photo')
        // and the restored instance goes on sealing to the same key: the public half came back with the control schema
        ->and(InstanceRecoveryKey::find(1)->fingerprint)->toBe($pair['fingerprint']);
});

test('a zip with sealed keys is refused before anything is touched when no way to open them is given, or the wrong one is', function () {
    [$one, $two] = isolatedInstance($this);
    $pair = app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, false, null)['path'];
    $other = RecoveryKey::generate();
    $typo = $pair['private'];
    $typo[8] = $typo[8] === 'A' ? 'B' : 'A';

    // The instance is still whole after each refusal: nothing was dropped.
    $before = Warehouse::count();
    expect(fn () => app(InstanceBackupService::class)->restore($zip, null, true))->toThrow(RuntimeException::class, 'sealed')
        ->and(fn () => app(InstanceBackupService::class)->restore($zip, null, true, null, null, $other['private']))->toThrow(RuntimeException::class, $pair['fingerprint'])
        ->and(fn () => app(InstanceBackupService::class)->restore($zip, null, true, null, null, $typo))->toThrow(RuntimeException::class, 'typing mistake')
        ->and(fn () => app(InstanceBackupService::class)->restore($zip, null, true, null, null, 'not a key'))->toThrow(RuntimeException::class);
    expect(Warehouse::count())->toBe($before)->and(Warehouse::find($one->id))->not->toBeNull();
});

test('--without-keys and a keys folder are the other ways to restore a zip with sealed keys', function () {
    [$one, $two, $oneKey] = isolatedInstance($this);
    app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, false, null)['path'];
    foreach ([$one, $two] as $w) {
        File::ensureDirectoryExists($this->tmp."/keys/{$w->id}");
        copy($w->secretsPath('warehouse.key'), $this->tmp."/keys/{$w->id}/warehouse.key");
    }

    wipeInstance($this, [$one, $two]);
    $without = app(InstanceBackupService::class)->restore($zip, null, false, null, null, null, true);
    expect($without['keys'])->toBeFalse()->and(implode(' ', $without['notes']))->toContain('left sealed')->and(Warehouse::find($one->id)->hasKey())->toBeFalse();

    wipeInstance($this, [Warehouse::find($one->id), Warehouse::find($two->id)]);
    $fromFolder = app(InstanceBackupService::class)->restore($zip, null, false, null, $this->tmp.'/keys');
    expect($fromFolder['keys'])->toBeTrue()->and(trim(file_get_contents(Warehouse::find($one->id)->secretsPath('warehouse.key'))))->toBe($oneKey);
});

test('restore:instance asks for the recovery key, reads it from the downloaded file, and refuses a bad one', function () {
    [$one, $two, $oneKey] = isolatedInstance($this);
    $pair = app(InstanceRecoveryKeys::class)->generate('admin@example.org');
    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, false, null)['path'];
    $file = $this->tmp.'/key.txt';
    file_put_contents($file, "Recovery key:\n  {$pair['private']}\n");
    file_put_contents($this->tmp.'/bad.txt', 'RK1-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-AAAAA');
    wipeInstance($this, [$one, $two]);

    $this->artisan('restore:instance', ['zip' => $zip, '--no-interaction' => true])->expectsOutputToContain('sealed')->assertFailed();
    $this->artisan('restore:instance', ['zip' => $zip, '--recovery-key-file' => $this->tmp.'/bad.txt', '--no-interaction' => true])->assertFailed();
    $this->artisan('restore:instance', ['zip' => $zip, '--recovery-key-file' => $this->tmp.'/missing.txt', '--no-interaction' => true])->expectsOutputToContain('not readable')->assertFailed();

    $this->artisan('restore:instance', ['zip' => $zip, '--recovery-key-file' => $file, '--no-interaction' => true])->expectsOutputToContain('and keys')->assertSuccessful();
    expect(trim(file_get_contents(Warehouse::find($one->id)->secretsPath('warehouse.key'))))->toBe($oneKey);
});

test('warehouse:key shows a key, refuses a bad one, and replaces the key of a warehouse in use only with --force', function () {
    $warehouse = Warehouse::default();
    $original = $warehouse->key();
    $newKey = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
    file_put_contents($this->tmp.'/new.key', $newKey);
    file_put_contents($this->tmp.'/bad.key', 'not-a-key');

    try {
        Artisan::call('warehouse:key', ['action' => 'show', 'warehouse' => $warehouse->id]);
        expect(trim(Artisan::output()))->toBe($original);

        $this->artisan('warehouse:key', ['action' => 'import', 'warehouse' => $warehouse->id, '--file' => $this->tmp.'/bad.key'])->assertFailed();
        // A set-up warehouse (the test one is) keeps its key unless forced.
        $this->artisan('warehouse:key', ['action' => 'import', 'warehouse' => $warehouse->id, '--file' => $this->tmp.'/new.key'])->assertFailed();
        expect($warehouse->key())->toBe($original);

        $this->artisan('warehouse:key', ['action' => 'import', 'warehouse' => $warehouse->id, '--file' => $this->tmp.'/new.key', '--force' => true])->assertSuccessful();
        expect($warehouse->key())->toBe($newKey)
            ->and(InstanceAuditLog::whereIn('action', ['warehouse.key_revealed', 'warehouse.key_imported'])->count())->toBeGreaterThanOrEqual(2);
    } finally {
        $warehouse->replaceKey($original);
        app(WarehouseContext::class)->refresh();
    }
});
