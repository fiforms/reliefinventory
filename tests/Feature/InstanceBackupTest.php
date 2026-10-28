<?php

// MULTI_WAREHOUSE.md §11: the whole-instance backup and restore, against real schemas and the provisioning test user of
// scripts/TESTING.md (skips without it). The restore test points the control and accounts connections at schemas of its own
// first, because a restore drops what it finds.

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use App\Services\BackupCompatibility;
use App\Services\BackupService;
use App\Services\InstanceBackupService;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
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
        $this->markTestSkipped('The provisioning test user (scripts/TESTING.md) does not exist here.');
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

test('a whole instance is backed up, wiped and restored, with its warehouses, logins, files, keys and database users', function () {
    // An instance of its own: new control and accounts schemas, migrated, and a warehouse registered in them.
    $suffix = bin2hex(random_bytes(2));
    $names = ['control' => "test_reliefinventory_ic{$suffix}_control", 'accounts' => "test_reliefinventory_ic{$suffix}_accounts"];
    $this->extraSchemas = array_values($names);
    foreach ($names as $connection => $name) {
        $this->admin->exec("CREATE DATABASE `{$name}`");
        config(["database.connections.{$connection}.database" => $name]);
        DB::purge($connection);
        Artisan::call('migrate:fresh', ['--database' => $connection, '--path' => "database/migrations/{$connection}", '--force' => true]);
    }

    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    [$one] = $creator->create('Restored One', null, 'test');
    [$two] = $creator->create('Restored Two', null, 'test');
    foreach ([$one, $two] as $w) {
        $this->created[] = ['id' => $w->id, 'schema' => $w->schema_name];
    }
    $account = new Account(['email' => 'kept@example.org']);
    $account->forceFill(['password' => Hash::make('kept-password-1'), 'email_verified_at' => now()])->save();
    $oneKey = trim(file_get_contents($one->secretsPath('warehouse.key')));
    File::ensureDirectoryExists($one->storagePath('receiving-photos'));
    file_put_contents($one->storagePath('receiving-photos/p.jpg'), 'one-photo');
    app(WarehouseContext::class)->run($two, fn () => DB::table('people')->insert(['first_name' => 'Marker', 'last_name' => 'Person', 'email' => 'marker@example.org', 'created_at' => now(), 'updated_at' => now()]));

    $zip = app(InstanceBackupService::class)->create($this->tmp.'/zips', null, true, 'a long passphrase')['path'];

    // Disaster: everything gone.
    foreach ([$one, $two] as $w) {
        $this->admin->exec('DROP DATABASE `'.$w->schema_name.'`');
        $this->admin->exec("DROP USER 'ri_".strtolower($w->id)."'@'localhost'");
        File::deleteDirectory($w->secretsPath());
        File::deleteDirectory(dirname($w->storagePath()));
    }
    foreach ($names as $name) {
        $this->admin->exec("DROP DATABASE `{$name}`");
        $this->admin->exec("CREATE DATABASE `{$name}`");   // a new instance starts with empty schemas (the provisioner makes them)
    }
    DB::purge('control');
    DB::purge('accounts');

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
