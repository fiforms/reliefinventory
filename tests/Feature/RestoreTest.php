<?php

use App\Console\Commands\SetupReset;
use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Backup;
use App\Models\Control\Warehouse;
use App\Models\SetupState;
use App\Models\User;
use App\Services\BackupCompatibility;
use App\Services\BackupService;
use App\Services\Federation\InstanceIdentity;
use App\Services\FirstRunService;
use App\Services\RestoreService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\SchemaSnapshot;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-restore-test-'.bin2hex(random_bytes(4));
    foreach (['out', 'private', 'public'] as $d) {
        mkdir($this->tmp."/{$d}", 0777, true);
    }
    config([
        'backup.dir' => $this->tmp.'/out',
        'filesystems.disks.local.root' => $this->tmp.'/private',
        'filesystems.disks.public.root' => $this->tmp.'/public',
        'backup.upload_chunk_bytes' => 50_000,
    ]);
});

afterEach(function () {
    @unlink(storage_path('framework/down')); // a failed --force test must not leave the dev site in maintenance mode
    if (! empty($GLOBALS['restore_test_dirtied_db'])) {
        Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions());
        $GLOBALS['restore_test_dirtied_db'] = false;
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
});

/** Hand-build a backup zip so malformed/hostile ones can be tested without a real database. */
const DUMP_END = "\n-- Dump completed on 2026-10-20 00:00:00\n";

function craftZip(string $path, array $files, array $manifestOverrides = [], ?array $entriesOverride = null): void
{
    $entries = [];
    foreach ($files as $name => $content) {
        $entries[] = ['path' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
    }
    $manifest = array_merge([
        'format' => 1, 'created_at' => now()->toIso8601String(), 'app' => ['commit' => 'abc'],
        'database' => ['migrations' => app(BackupCompatibility::class)->knownMigrations()],
        'includes_uploads' => false, 'contains_app_key' => false, 'entries' => $entriesOverride ?? $entries,
    ], $manifestOverrides);
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();
}

/** Put a zip through the real upload endpoints in small chunks and return its upload id. */
function uploadZip(string $path, array $extra = []): string
{
    $size = filesize($path);
    $start = test()->postJson('/install/restore', ['filename' => 'b.zip', 'size' => $size] + $extra)->assertCreated();
    $id = $start->json('upload_id');
    $bytes = file_get_contents($path);
    for ($offset = 0; $offset < $size; $offset += 50_000) {
        test()->call('PUT', "/install/restore/{$id}?offset={$offset}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], substr($bytes, $offset, 50_000))->assertOk();
    }

    return $id;
}

/** Ask the wizard to start the restore once the upload(s) are up. */
function launchRestore(string $id, ?string $storageId = null)
{
    return test()->postJson('/install/restore/launch', ['upload_id' => $id] + ($storageId ? ['storage_upload_id' => $storageId] : []));
}

function freshInstance(): void
{
    SetupState::current()->update(['completed_at' => null]);
}

// ---- compatibility ------------------------------------------------------------------------------

test('a manifest from this version is compatible', function () {
    $dump = 'SELECT 1;';
    craftZip($this->tmp.'/ok.zip', ['database/db.sql' => $dump]);
    $zip = new ZipArchive;
    $zip->open($this->tmp.'/ok.zip');
    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect(app(BackupCompatibility::class)->problems($manifest, ['manifest.json', 'database/db.sql']))->toBe([]);
});

test('a backup from newer software is refused by its migration list, an older one is fine', function () {
    $known = app(BackupCompatibility::class)->knownMigrations();
    $base = ['format' => 1, 'contains_app_key' => false, 'entries' => [['path' => 'database/db.sql', 'size' => 1, 'sha256' => str_repeat('a', 64)]]];
    $names = ['manifest.json', 'database/db.sql'];

    $newer = app(BackupCompatibility::class)->problems($base + ['database' => ['migrations' => [...$known, '2099_01_01_000000_future_change']]], $names);
    expect($newer)->toHaveCount(1)->and($newer[0])->toContain('newer software', '2099_01_01_000000_future_change');

    $older = app(BackupCompatibility::class)->problems($base + ['database' => ['migrations' => array_slice($known, 0, 5)]], $names);
    expect($older)->toBe([]);

    $newFormat = app(BackupCompatibility::class)->problems(['format' => 99] + $base, $names);
    expect($newFormat[0])->toContain('newer backup format');
});

test('unsafe or inconsistent manifests are refused', function () {
    $c = app(BackupCompatibility::class);
    $sha = str_repeat('a', 64);
    $migrations = ['database' => ['migrations' => []]];
    $entry = fn ($path) => ['path' => $path, 'size' => 1, 'sha256' => $sha];
    $base = fn (array $entries) => ['format' => 1, 'contains_app_key' => false, 'entries' => $entries] + $migrations;

    foreach (['files/private/../../../etc/passwd', '/etc/passwd', 'files/private//x', 'files/other/x', 'files\\private\\x', 'database/db.sql.php', 'files/private/./x'] as $bad) {
        $problems = $c->problems($base([$entry('database/db.sql'), $entry($bad)]), ['manifest.json', 'database/db.sql', $bad], []);
        expect($problems)->not->toBe([], "accepted {$bad}");
    }

    // extra file in the zip the manifest doesn't list; listed file missing from the zip; no dump; key claimed
    expect($c->problems($base([$entry('database/db.sql')]), ['manifest.json', 'database/db.sql', 'files/private/sneaky.php'], []))->not->toBe([]);
    expect($c->problems($base([$entry('database/db.sql'), $entry('files/private/a')]), ['manifest.json', 'database/db.sql'], []))->not->toBe([]);
    expect($c->problems($base([$entry('files/private/a')]), ['manifest.json', 'files/private/a'], []))->not->toBe([]);
    expect($c->problems(['contains_app_key' => true] + $base([$entry('database/db.sql')]), ['manifest.json', 'database/db.sql'], []))->not->toBe([]);
});

test('scope decides what a manifest must contain, and a zip whose database is unused skips the version check', function () {
    $c = app(BackupCompatibility::class);
    $sha = str_repeat('a', 64);
    $entry = fn ($path) => ['path' => $path, 'size' => 1, 'sha256' => $sha];
    $future = ['database' => ['migrations' => [...$c->knownMigrations(), '2099_01_01_000000_future']]];

    // files-only: no dump needed, no migration list needed, and even an empty disk is a valid backup
    $files = ['format' => 2, 'scope' => 'storage', 'contains_app_key' => false, 'entries' => [$entry('files/private/a')]];
    expect($c->problems($files, ['manifest.json', 'files/private/a']))->toBe([]);
    expect($c->problems(['entries' => []] + $files, ['manifest.json']))->toBe([]);
    // ... but it must not smuggle in a dump
    expect($c->problems(['entries' => [$entry('database/db.sql')]] + $files, ['manifest.json', 'database/db.sql']))->not->toBe([]);

    // a database zip with nothing in it is still refused, and an unknown scope is not guessed at
    expect($c->problems(['scope' => 'database', 'entries' => []] + $files, ['manifest.json']))->not->toBe([]);
    expect($c->problems(['scope' => 'everything'] + $files, ['manifest.json', 'files/private/a']))->not->toBe([]);

    // format-1 zips had no scope: with uploads they are full, without they are database-only
    expect($c->scope(['format' => 1, 'includes_uploads' => true]))->toBe('full')
        ->and($c->scope(['format' => 1]))->toBe('database')
        ->and($c->scope(['scope' => 'storage']))->toBe('storage');

    // a full zip that only lends its files may have a newer schema; as the database source it may not
    $full = ['format' => 2, 'scope' => 'full', 'contains_app_key' => false, 'entries' => [$entry('database/db.sql'), $entry('files/private/a')]] + $future;
    $names = ['manifest.json', 'database/db.sql', 'files/private/a'];
    expect($c->problems($full, $names, null, false))->toBe([]);
    expect($c->problems($full, $names))->not->toBe([]);
});

test('every encrypted model cast is registered so a restore knows to blank it', function () {
    $registered = array_keys(config('backup.encrypted_columns'));
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $ref = new ReflectionClass($class);
        if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
            continue;
        }
        $model = new $class;
        foreach ($model->getCasts() as $column => $cast) {
            if (str_starts_with((string) $cast, 'encrypted')) {
                expect($registered)->toContain($model->getTable().'.'.$column);
            }
        }
    }
});

// ---- wizard endpoints ---------------------------------------------------------------------------

test('restore endpoints only work on an instance that has never been set up', function () {
    // Pest.php marks setup complete for every feature test
    $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10])->assertForbidden();

    freshInstance();
    $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10])->assertCreated();

    SetupState::current()->update(['completed_at' => now()]);
    $this->putJson('/install/restore/'.str_repeat('a', 40).'?offset=0')->assertForbidden();
});

test('the setup code is required to start a restore when one is configured', function () {
    freshInstance();
    config(['app.setup_code' => 'letmein']);

    $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10])->assertStatus(422);
    $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10, 'setup_code' => 'wrong'])->assertStatus(422);
    $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10, 'setup_code' => 'letmein'])->assertCreated();
});

test('the chunked upload resumes, refuses gaps, and an incomplete upload cannot start a restore', function () {
    Process::fake();
    freshInstance();
    $id = $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10])->json('upload_id');

    $put = fn ($offset, $bytes) => $this->call('PUT', "/install/restore/{$id}?offset={$offset}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $bytes);
    $put(0, 'abcde')->assertOk()->assertJsonPath('received', 5);
    $put(0, 'abcde')->assertStatus(409)->assertJsonPath('received', 5);   // repeated chunk
    $put(9, 'x')->assertStatus(409)->assertJsonPath('received', 5);        // gap
    $this->getJson("/install/restore/{$id}")->assertJsonPath('received', 5);
    $this->postJson("/install/restore/{$id}/complete")->assertStatus(409);
    launchRestore($id)->assertStatus(409);
    Process::assertNothingRan();

    $put(5, 'fghij')->assertOk();
    $this->postJson("/install/restore/{$id}/complete")->assertOk(); // "all here" — starts nothing by itself
    Process::assertNothingRan();
    launchRestore($id)->assertStatus(202);
    Process::assertRan(fn ($p) => str_contains($p->command, "restore:run '{$id}'") && str_contains($p->command, 'setsid'));
    expect($this->getJson('/install/restore/status')->assertOk()->headers->get('Cache-Control'))->toContain('no-store');
});

test('a second, files-only upload can be launched together with the main one', function () {
    Process::fake();
    freshInstance();
    $upload = function (string $bytes) {
        $id = test()->postJson('/install/restore', ['filename' => 'b.zip', 'size' => strlen($bytes)])->json('upload_id');
        test()->call('PUT', "/install/restore/{$id}?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $bytes)->assertOk();

        return $id;
    };
    $main = $upload('main-zip');
    $files = $upload('files-zip');

    launchRestore($main, $main)->assertStatus(422);                                  // must be two different uploads
    launchRestore($main, str_repeat('b', 40))->assertNotFound();                      // and a real one
    launchRestore($main, $files)->assertStatus(202);
    Process::assertRan(fn ($p) => str_contains($p->command, "restore:run '{$main}' '{$files}'"));
});

test('a hostile upload id cannot escape the restore directory', function () {
    freshInstance();
    $this->getJson('/install/restore/'.str_repeat('..', 20))->assertNotFound();
    $this->deleteJson('/install/restore/'.str_repeat('a', 40))->assertNotFound();
});

// ---- the restore itself -------------------------------------------------------------------------

test('a damaged backup is rejected before anything on the instance changes', function () {
    Process::fake();
    freshInstance();
    $files = ['database/db.sql' => 'DROP TABLE people;'.DUMP_END, 'files/private/a.jpg' => 'photo'];
    craftZip($this->tmp.'/bad.zip', $files, ['scope' => 'full', 'includes_uploads' => true]);
    // tamper: swap a.jpg's bytes after the manifest recorded its hash
    $zip = new ZipArchive;
    $zip->open($this->tmp.'/bad.zip');
    $zip->addFromString('files/private/a.jpg', 'PHOTO');
    $zip->close();

    $id = uploadZip($this->tmp.'/bad.zip');
    launchRestore($id)->assertStatus(202);
    $peopleBefore = DB::table('people')->count();
    app(RestoreService::class)->run($id);

    $status = app(RestoreService::class)->status();
    expect($status['state'])->toBe('failed')->and($status['error'])->toContain('checksum', 'files/private/a.jpg')
        ->and(Schema::hasTable('people'))->toBeTrue()
        ->and(DB::table('people')->count())->toBe($peopleBefore)
        ->and(file_exists($this->tmp.'/private/a.jpg'))->toBeFalse()
        ->and(glob($this->tmp.'/out/restore/*/'))->toBe([]); // upload and work files cleaned up
});

test('a backup from newer software is rejected up front', function () {
    Process::fake();
    freshInstance();
    craftZip($this->tmp.'/new.zip', ['database/db.sql' => 'SELECT 1;'.DUMP_END], [
        'database' => ['migrations' => [...app(BackupCompatibility::class)->knownMigrations(), '2099_01_01_000000_future']],
    ]);
    $id = uploadZip($this->tmp.'/new.zip');
    launchRestore($id);
    app(RestoreService::class)->run($id);

    expect(app(RestoreService::class)->status())->toMatchArray(['state' => 'failed'])
        ->and(app(RestoreService::class)->status()['error'])->toContain('newer software');
});

test('a not-a-backup file fails cleanly', function () {
    Process::fake();
    freshInstance();
    file_put_contents($this->tmp.'/junk.zip', str_repeat('not a zip', 100));
    $id = uploadZip($this->tmp.'/junk.zip');
    launchRestore($id);
    app(RestoreService::class)->run($id);

    expect(app(RestoreService::class)->status()['state'])->toBe('failed');
});

test('a full backup restores onto a fresh instance, blanking what the new key cannot read', function () {
    // Restoring wipes and reloads the whole database (the disposable test one), which also commits the
    // RefreshDatabase transaction, so rebuild a clean schema when done — see afterEach.
    $GLOBALS['restore_test_dirtied_db'] = true;
    Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions());

    // The "old instance": an administrator, a photo, a federation key encrypted under some other key.
    $admin = User::factory()->create(['email' => 'old-admin@example.com']);
    DB::table('people_roles')->insert(['person_id' => $admin->id, 'role_id' => 15, 'created_at' => now(), 'updated_at' => now()]);
    // This instance's federation identity, its private key encrypted under some other APP_KEY.
    DB::table('federation_identities')->insert([
        'uuid' => '11111111-2222-4333-8444-555555555555', 'name' => 'old-box', 'public_key' => str_repeat('A', 43),
        'private_key' => (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('not-a-real-key'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    mkdir($this->tmp.'/private/receiving-photos', 0777, true);
    file_put_contents($this->tmp.'/private/receiving-photos/p.jpg', 'photo-bytes');

    $backup = Backup::create(['status' => 'queued', 'scope' => 'full']);
    app(BackupService::class)->run($backup);
    $backup = $backup->fresh();
    expect($backup->status)->toBe('complete')->and($backup->error)->toBeNull();

    // The "new instance": empty schema, no logins, never set up, no photo.
    Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions());
    Membership::query()->delete();
    Account::query()->delete();
    unlink($this->tmp.'/private/receiving-photos/p.jpg');
    SetupState::current()->update(['completed_at' => null]);
    expect(User::where('email', 'old-admin@example.com')->exists())->toBeFalse();

    Process::fake();
    $id = uploadZip($backup->path());
    launchRestore($id)->assertStatus(202);
    app(RestoreService::class)->run($id);

    $status = app(RestoreService::class)->status();
    expect($status['state'])->toBe('complete', $status['error'] ?? '')
        ->and($status['blanked'])->toBe(['Federation identity key (peers must re-approve a new one)'])
        ->and($status['admin_missing'])->toBeFalse()
        ->and(User::where('email', 'old-admin@example.com')->exists())->toBeTrue()
        ->and(file_get_contents($this->tmp.'/private/receiving-photos/p.jpg'))->toBe('photo-bytes')
        ->and(DB::table('federation_identities')->value('name'))->toBe('old-box')
        ->and(DB::table('federation_identities')->value('private_key'))->toBeNull()
        ->and(InstanceIdentity::isLost())->toBeTrue()
        ->and(DB::table('backups')->count())->toBe(0)
        ->and(SetupState::current()->completed_at)->not->toBeNull();

    // The logins came with it (database/accounts.json): the administrator signs in with their old password
    // and, the instance having no instance admin yet, runs it.
    expect(Account::findByEmail('old-admin@example.com')->is_instance_admin)->toBeTrue()
        ->and($status['logins'])->toBe(['old-admin@example.com: login created']);

    // The instance is now a normal, set-up one: the wizard and restore are closed.
    $this->get('/install')->assertRedirect(route('login'));
    $this->postJson('/install/restore', ['filename' => 'b.zip', 'size' => 10])->assertForbidden();
    expect($this->getJson('/install/restore/status')->json('state'))->toBe('complete');

    $this->post('/login', ['email' => 'old-admin@example.com', 'password' => 'password']);
    $this->assertAuthenticated();
});

// ---- restoring from zips on disk (backup:restore) -----------------------------------------------------

test('restoring onto an instance that is already set up needs --force, and refuses before touching anything', function () {
    craftZip($this->tmp.'/db.zip', ['database/db.sql' => 'DROP TABLE people;'.DUMP_END]);
    $before = DB::table('people')->count();

    expect(fn () => app(RestoreService::class)->runFromFiles($this->tmp.'/db.zip', null, false))
        ->toThrow(RuntimeException::class, '--force');
    $this->artisan('backup:restore', ['database' => $this->tmp.'/db.zip'])->assertFailed();

    expect(Schema::hasTable('people'))->toBeTrue()->and(DB::table('people')->count())->toBe($before);
});

test('backup:restore needs something to restore, and says so about a file that is not there', function () {
    $this->artisan('backup:restore')->assertFailed();
    $this->artisan('backup:restore', ['database' => $this->tmp.'/missing.zip', '--force' => true, '--no-interaction' => true])->assertFailed();
});

test('the main zip must hold a database and the files zip must hold files', function () {
    $service = app(BackupService::class);
    $filesOnly = $service->createIn($this->tmp.'/out', 'storage', 'files-only')['path'];
    $dbOnly = $service->createIn($this->tmp.'/out', 'database', 'db-only')['path'];

    expect(fn () => app(RestoreService::class)->runFromFiles($filesOnly, null, true))
        ->toThrow(RuntimeException::class, 'only files');
    expect(fn () => app(RestoreService::class)->runFromFiles(null, $dbOnly, true))
        ->toThrow(RuntimeException::class, 'only a database');
});

test('a files-only restore lays the files down and leaves the database alone', function () {
    mkdir($this->tmp.'/private/receiving-photos', 0777, true);
    file_put_contents($this->tmp.'/private/receiving-photos/p.jpg', 'good-photo');
    $zip = app(BackupService::class)->createIn($this->tmp.'/out', 'storage', 'files')['path'];
    file_put_contents($this->tmp.'/private/receiving-photos/p.jpg', 'damaged');
    file_put_contents($this->tmp.'/private/keep-me.txt', 'newer than the backup');
    $people = DB::table('people')->count();

    $result = app(RestoreService::class)->runFromFiles(null, $zip, true);

    expect($result['database_restored'])->toBeFalse()->and($result['includes_uploads'])->toBeTrue()
        ->and(file_get_contents($this->tmp.'/private/receiving-photos/p.jpg'))->toBe('good-photo')
        ->and(file_get_contents($this->tmp.'/private/keep-me.txt'))->toBe('newer than the backup') // nothing is deleted
        ->and(DB::table('people')->count())->toBe($people)
        ->and(file_exists(storage_path('framework/down')))->toBeFalse()                              // no maintenance mode for a files-only restore
        ->and(app(RestoreService::class)->status()['state'])->toBe('complete');
});

test('a damaged database is repaired from a database zip plus the best copy of the files', function () {
    // Restoring wipes and reloads the whole (disposable) test database; rebuild a clean schema afterwards.
    $GLOBALS['restore_test_dirtied_db'] = true;
    Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions());
    $service = app(BackupService::class);

    // Monday: the database backup. The photo is still the first version.
    $admin = User::factory()->create(['email' => 'admin@example.com']);
    DB::table('people_roles')->insert(['person_id' => $admin->id, 'role_id' => 15, 'created_at' => now(), 'updated_at' => now()]);
    mkdir($this->tmp.'/private/receiving-photos', 0777, true);
    file_put_contents($this->tmp.'/private/receiving-photos/p.jpg', 'monday-photo');
    $databaseZip = $service->createIn($this->tmp.'/out', 'database', 'monday-db')['path'];

    // Tuesday: more data and a newer photo; the best copy of the files is a FULL backup whose database is newer than Monday's.
    User::factory()->create(['email' => 'tuesday@example.com']);
    file_put_contents($this->tmp.'/private/receiving-photos/p.jpg', 'tuesday-photo');
    $filesZip = $service->createIn($this->tmp.'/out', 'full', 'tuesday-full')['path'];

    // Wednesday: the database is damaged and the photo is gone with the disk.
    DB::table('people')->where('email', 'tuesday@example.com')->delete();
    unlink($this->tmp.'/private/receiving-photos/p.jpg');
    SetupState::current()->update(['completed_at' => now()]);   // a normal, set-up instance, not a fresh one

    $result = app(RestoreService::class)->runFromFiles($databaseZip, $filesZip, true);

    expect($result['database_restored'])->toBeTrue()->and($result['includes_uploads'])->toBeTrue()
        ->and(User::where('email', 'admin@example.com')->exists())->toBeTrue()
        ->and(User::where('email', 'tuesday@example.com')->exists())->toBeFalse()   // the full zip's newer database was ignored
        ->and(file_get_contents($this->tmp.'/private/receiving-photos/p.jpg'))->toBe('tuesday-photo')
        ->and($result['notes'])->toContain('The files backup also contained a database; it was ignored.')
        ->and(Warehouse::current()->fresh()->status)->toBe('active')                 // the warehouse was out of service meanwhile, and is back
        ->and(SetupState::current()->completed_at)->not->toBeNull();
});

test('a failed database load on a set-up instance leaves the warehouse out of service instead of opening the setup wizard', function () {
    $GLOBALS['restore_test_dirtied_db'] = true;
    craftZip($this->tmp.'/broken.zip', ['database/db.sql' => 'THIS IS NOT SQL;'.DUMP_END]);

    $this->artisan('backup:restore', ['database' => $this->tmp.'/broken.zip', '--force' => true, '--no-interaction' => true])->assertFailed();

    expect(Warehouse::current()->fresh()->status)->toBe('restoring')
        ->and(file_exists(storage_path('framework/down')))->toBeFalse();   // never Laravel's instance-wide maintenance mode
});

test('a truncated database dump is refused before anything is wiped', function () {
    craftZip($this->tmp.'/cut.zip', ['database/db.sql' => "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1),(2"]);   // no end-of-dump marker
    $people = DB::table('people')->count();

    expect(fn () => app(RestoreService::class)->runFromFiles($this->tmp.'/cut.zip', null, true))
        ->toThrow(RuntimeException::class, 'incomplete');

    expect(DB::table('people')->count())->toBe($people)->and(app()->isDownForMaintenance())->toBeFalse();
});

// ---- setup:reset ----------------------------------------------------------------------------------------

test('setup:reset cannot be scripted: without a real terminal it refuses, even with every flag', function () {
    $people = DB::table('people')->count();
    User::factory()->create();

    $this->artisan('setup:reset')->assertFailed();                                 // no TTY under test
    $this->artisan('setup:reset', ['--no-interaction' => true])->assertFailed();
    expect(fn () => Artisan::call('setup:reset', ['--force' => true]))                // there is no such option to skip anything
        ->toThrow(InvalidOptionException::class);

    expect(DB::table('people')->count())->toBe($people + 1);
});

/** The same command, standing in for a person at a keyboard (production decides this from the real STDIN). */
function resetWithTerminal(): void
{
    Artisan::registerCommand(new class extends SetupReset
    {
        protected function hasTerminal(): bool
        {
            return true;
        }
    });
}

test('setup:reset does nothing unless the exact phrase is typed', function () {
    resetWithTerminal();
    User::factory()->create();
    $people = DB::table('people')->count();

    foreach (['yes', 'yes-delete-all-my-data', 'YES-DELETE-ALL-MY-DATA ', ''] as $wrong) {
        $this->artisan('setup:reset')
            ->expectsQuestion('Type YES-DELETE-ALL-MY-DATA exactly to continue', $wrong)
            ->assertFailed();
    }

    expect(DB::table('people')->count())->toBe($people);
});

test('setup:reset with the phrase typed returns the instance to brand new', function () {
    $GLOBALS['restore_test_dirtied_db'] = true;
    resetWithTerminal();
    User::factory()->create(['email' => 'doomed@example.com']);
    file_put_contents($this->tmp.'/private/keep.txt', 'files are not touched');

    $this->artisan('setup:reset')
        ->expectsQuestion('Type YES-DELETE-ALL-MY-DATA exactly to continue', 'YES-DELETE-ALL-MY-DATA')
        ->assertSuccessful();

    expect(User::where('email', 'doomed@example.com')->exists())->toBeFalse()
        ->and(DB::table('people')->whereNull('system_key')->count())->toBe(0)    // no real people; only the built-in Unknown Donor placeholder
        ->and(Schema::hasTable('migrations'))->toBeTrue()
        ->and(DB::table('statuses')->count())->toBeGreaterThan(0)          // essential lookup data is back
        ->and(file_get_contents($this->tmp.'/private/keep.txt'))->toBe('files are not touched')
        ->and(app(FirstRunService::class)->needsSetup())->toBeTrue();
});
