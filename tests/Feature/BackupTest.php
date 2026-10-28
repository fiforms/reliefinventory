<?php

use App\Models\Backup;
use App\Models\Control\Warehouse;
use App\Services\BackupService;
use App\Services\DatabaseTools;
use App\Services\RestoreService;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseLayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\SecondWarehouse;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-backup-test-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/out', 0777, true);
    foreach (['private', 'public'] as $disk) {
        mkdir($this->tmp."/{$disk}", 0777, true);
    }
    config([
        'backup.dir' => $this->tmp.'/out',
        'filesystems.disks.local.root' => $this->tmp.'/private',
        'filesystems.disks.public.root' => $this->tmp.'/public',
    ]);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->tmp));
});

function runBackup(string $scope): Backup
{
    $backup = Backup::create(['status' => 'queued', 'scope' => $scope]);
    app(BackupService::class)->run($backup);

    return $backup->fresh();
}

function zipEntries(Backup|string $backup): array
{
    $zip = new ZipArchive;
    $zip->open(is_string($backup) ? $backup : $backup->path());
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $contents = fn ($name) => $zip->getFromName($name);

    return [$names, $manifest, $contents];
}

test('a database-only backup holds a verifiable dump and manifest', function () {
    $backup = runBackup('database');

    expect($backup->status)->toBe('complete')->and($backup->error)->toBeNull()
        ->and($backup->expires_at)->not->toBeNull();
    [$names, $manifest, $contents] = zipEntries($backup);

    expect($names)->toContain('manifest.json', 'database/db.sql')
        ->and($manifest['format'])->toBe(2)
        ->and($manifest['scope'])->toBe('database')
        ->and($manifest['includes_database'])->toBeTrue()
        ->and($manifest['includes_uploads'])->toBeFalse()
        ->and($manifest['contains_app_key'])->toBeFalse()
        ->and($manifest['database']['migrations'])->not->toBeEmpty();

    $entry = $manifest['entries'][0];
    expect($entry['path'])->toBe('database/db.sql')
        ->and(hash('sha256', $contents('database/db.sql')))->toBe($entry['sha256'])
        ->and(strlen($contents('database/db.sql')))->toBe($entry['size']);
});

test('uploads are included with hashes, excluded dirs and key files are left out', function () {
    mkdir($this->tmp.'/private/receiving-photos', 0777, true);
    file_put_contents($this->tmp.'/private/receiving-photos/a.jpg', 'photo-bytes');
    mkdir($this->tmp.'/private/device-releases/x', 0777, true);
    file_put_contents($this->tmp.'/private/device-releases/x/firmware.img', 'huge');
    mkdir($this->tmp.'/private/print-jobs', 0777, true);
    file_put_contents($this->tmp.'/private/print-jobs/j.pdf', 'pdf');
    file_put_contents($this->tmp.'/private/.app_key', 'base64:SECRETSECRET');
    file_put_contents($this->tmp.'/private/.env', 'APP_KEY=base64:SECRETSECRET');

    $backup = runBackup('full');
    [$names, $manifest, $contents] = zipEntries($backup);

    // The warehouse's own files only (there is no public disk any more), and its logins beside the dump.
    expect($names)->toContain('database/db.sql', 'database/accounts.json', 'files/private/receiving-photos/a.jpg')
        ->and(collect($names)->filter(fn ($n) => str_starts_with($n, 'files/public/'))->all())->toBe([])
        ->and($manifest['scope'])->toBe('full')
        ->and($names)->not->toContain('files/private/device-releases/x/firmware.img')
        ->and($names)->not->toContain('files/private/print-jobs/j.pdf')
        ->and($names)->not->toContain('files/private/.app_key', 'files/private/.env')
        ->and($manifest['includes_uploads'])->toBeTrue();

    $photo = collect($manifest['entries'])->firstWhere('path', 'files/private/receiving-photos/a.jpg');
    expect($photo['sha256'])->toBe(hash('sha256', 'photo-bytes'));

    // Nothing anywhere in the zip contains the key.
    expect(str_contains(file_get_contents($backup->path()), 'SECRETSECRET'))->toBeFalse()
        ->and(str_contains(json_encode($manifest), config('app.key')))->toBeFalse();
});

test('a files-only backup holds just the uploads and needs no database dump', function () {
    mkdir($this->tmp.'/private/receiving-photos', 0777, true);
    file_put_contents($this->tmp.'/private/receiving-photos/a.jpg', 'photo-bytes');
    file_put_contents($this->tmp.'/private/.app_key', 'base64:SECRETSECRET');

    $backup = runBackup('storage');
    [$names, $manifest] = zipEntries($backup);

    expect($backup->status)->toBe('complete')
        ->and($names)->toContain('manifest.json', 'files/private/receiving-photos/a.jpg')
        ->and($names)->not->toContain('database/db.sql', 'files/private/.app_key')
        ->and($manifest['scope'])->toBe('storage')
        ->and($manifest['includes_database'])->toBeFalse()
        ->and($manifest['database']['dump'])->toBeNull();
});

test('a backup can be written to any directory with no database record, so it works with the database down', function () {
    mkdir($this->tmp.'/elsewhere');
    mkdir($this->tmp.'/private/x', 0777, true);
    file_put_contents($this->tmp.'/private/x/f.txt', 'file');

    $result = app(BackupService::class)->createIn($this->tmp.'/elsewhere', 'storage', 'my-snapshot');

    expect($result['path'])->toBe($this->tmp.'/elsewhere/my-snapshot.zip')
        ->and($result['files'])->toBe(1)
        ->and(is_file($result['path']))->toBeTrue()
        ->and(Backup::count())->toBe(0)
        ->and(glob($this->tmp.'/elsewhere/.work-*'))->toBe([])
        ->and(glob($this->tmp.'/elsewhere/.partial-*'))->toBe([]);

    // never overwrites a backup that is already there
    expect(fn () => app(BackupService::class)->createIn($this->tmp.'/elsewhere', 'storage', 'my-snapshot'))
        ->toThrow(RuntimeException::class, 'already exists');
    // an unknown scope is refused outright
    expect(fn () => app(BackupService::class)->createIn($this->tmp.'/elsewhere', 'everything'))
        ->toThrow(RuntimeException::class, 'Unknown backup scope');
});

test('backup:create takes a scope, records the backup, and prints where the zip is', function () {
    $this->artisan('backup:create', ['--scope' => 'database'])->assertSuccessful();
    $backup = Backup::firstOrFail();
    expect($backup->scope)->toBe('database')->and($backup->status)->toBe('complete')->and(is_file($backup->path()))->toBeTrue();

    $this->artisan('backup:create', ['--scope' => 'bogus'])->assertFailed();
    expect(Backup::count())->toBe(1);
});

test('backup:create --dest writes an unrecorded zip with the requested name', function () {
    mkdir($this->tmp.'/tier');

    $this->artisan('backup:create', ['--scope' => 'database', '--dest' => $this->tmp.'/tier', '--name' => '20261020-020000'])->assertSuccessful();

    expect(is_file($this->tmp.'/tier/20261020-020000.zip'))->toBeTrue()->and(Backup::count())->toBe(0);
    [$names, $manifest] = zipEntries($this->tmp.'/tier/20261020-020000.zip');
    expect($names)->toContain('database/db.sql')->and($manifest['scope'])->toBe('database');

    // --name only makes sense with --dest, and must be a plain file name
    $this->artisan('backup:create', ['--name' => 'x'])->assertFailed();
    $this->artisan('backup:create', ['--dest' => $this->tmp.'/tier', '--name' => '../escape'])->assertFailed();
    $this->artisan('backup:create', ['--dest' => $this->tmp.'/tier', '--name' => 'x.zip'])->assertFailed();
});

test('only one backup can be started at a time', function () {
    Process::fake();
    $service = app(BackupService::class);

    $first = $service->start(null, 'database');
    expect($first->status)->toBe('queued');
    Process::assertRan(fn ($p) => str_contains($p->command, "backup:run {$first->id}") && str_contains($p->command, 'setsid'));

    expect(fn () => $service->start(null, 'database'))->toThrow(RuntimeException::class, 'already running');
});

test('a second runner cannot take the lock while one is running', function () {
    mkdir(app(BackupService::class)->dir(), 0750, true);
    $lock = fopen(app(BackupService::class)->dir().'/.backup.lock', 'c');
    flock($lock, LOCK_EX);

    $backup = runBackup('database');
    expect($backup->status)->toBe('failed')->and($backup->error)->toContain('already running');

    flock($lock, LOCK_UN);
});

test('a running backup whose process died is marked failed instead of blocking forever', function () {
    $stuck = Backup::create(['status' => 'running', 'scope' => 'database']);

    app(BackupService::class)->reapInterrupted();

    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->error)->toContain('stopped');
});

test('a running backup that still holds the lock is left alone', function () {
    $running = Backup::create(['status' => 'running', 'scope' => 'database']);
    mkdir(app(BackupService::class)->dir(), 0750, true);
    $lock = fopen(app(BackupService::class)->dir().'/.backup.lock', 'c');
    flock($lock, LOCK_EX);

    app(BackupService::class)->reapInterrupted();

    expect($running->fresh()->status)->toBe('running');
    flock($lock, LOCK_UN);
});

test('expired backups are pruned with their files', function () {
    $backup = runBackup('database');
    expect(is_file($backup->path()))->toBeTrue();

    $backup->update(['expires_at' => now()->subMinute()]);
    app(BackupService::class)->prune();

    expect(is_file($backup->path()))->toBeFalse()->and(Backup::find($backup->id))->toBeNull();
});

test('old zips with no record are pruned too, but only ones this app names, and only past the retention', function () {
    config(['backup.keep_days' => 14]);
    $dir = app(BackupService::class)->dir();
    mkdir($dir, 0750, true);
    $old = fn ($name) => tap("{$dir}/{$name}", fn ($path) => touch($path, now()->subDays(20)->getTimestamp()));
    $orphan = $old('relief-backup-20260101-000000-database-premigrate.zip');   // pre-migration backup: never had a row
    $unrelated = $old('someone-elses-notes.zip');
    file_put_contents("{$dir}/relief-backup-20261020-000000-database-premigrate.zip", 'recent');
    $kept = runBackup('database');
    touch($kept->path(), now()->subDays(20)->getTimestamp());                  // old file but its row says it hasn't expired

    app(BackupService::class)->prune();

    expect(is_file($orphan))->toBeFalse()
        ->and(is_file($unrelated))->toBeTrue()
        ->and(is_file("{$dir}/relief-backup-20261020-000000-database-premigrate.zip"))->toBeTrue()
        ->and(is_file($kept->path()))->toBeTrue();
});

test('backup endpoints require manage-backups', function () {
    $this->actingAs(userWithPermissions('general-access'))->getJson('/json/backups')->assertForbidden();
    $this->actingAs(userWithPermissions('admin-system'))->postJson('/json/backups')->assertForbidden();
    $this->actingAs(userWithPermissions('manage-backups'))->getJson('/json/backups')->assertOk();
});

test('the admin can start, download and delete a backup', function () {
    Process::fake();
    $admin = userWithPermissions('manage-backups');

    $started = $this->actingAs($admin)->postJson('/json/backups', ['scope' => 'full'])->assertStatus(202);
    expect($started->json('record.scope'))->toBe('full')
        ->and(Backup::first()->requested_by)->toBe($admin->id);
    $this->actingAs($admin)->postJson('/json/backups', ['scope' => 'database'])->assertStatus(409);

    $this->actingAs($admin)->deleteJson('/json/backups/'.Backup::first()->id)->assertStatus(409); // still running

    $done = runBackup('database');
    Backup::whereKey(Backup::first()->id)->update(['status' => 'failed']);
    $this->actingAs($admin)->get("/json/backups/{$done->id}/download")->assertOk()->assertDownload($done->filename);
    $this->actingAs($admin)->deleteJson("/json/backups/{$done->id}")->assertOk();
    expect(is_file($done->path()))->toBeFalse();
});

test('the app key needs its own permission, the right password and is never cached', function () {
    $password = 'correct-horse-battery';
    $viewer = userWithPermissions('view-app-key');
    $viewer->forceFill(['password' => bcrypt($password)])->save();

    // manage-backups alone is not enough
    $this->actingAs(userWithPermissions('manage-backups'))->postJson('/json/backups/app-key', ['password' => 'x'])->assertForbidden();

    $this->actingAs($viewer)->postJson('/json/backups/app-key', ['password' => 'wrong'])->assertStatus(422)->assertJsonMissing(['key' => config('app.key')]);
    $this->actingAs($viewer)->getJson('/json/backups/app-key')->assertStatus(405);

    $ok = $this->actingAs($viewer)->postJson('/json/backups/app-key', ['password' => $password])->assertOk();
    expect($ok->json('key'))->toBe(config('app.key'))->and($ok->headers->get('Cache-Control'))->toContain('no-store');
});

test('the page offers only a database or a database + files backup, never a files-only one', function () {
    $admin = userWithPermissions('manage-backups');
    $this->actingAs($admin)->postJson('/json/backups', ['scope' => 'storage'])->assertStatus(422);
    $this->actingAs($admin)->postJson('/json/backups', [])->assertStatus(422);
    expect(Backup::count())->toBe(0);
});

test('the merged page opens for either permission and tells each person what they may use', function () {
    $this->actingAs(userWithPermissions('manage-backups'))->get('/setup/system')
        ->assertInertia(fn ($page) => $page->component('SystemAdmin')
            ->where('canManageBackups', true)->where('canAdminSystem', false)->where('canViewAppKey', false));
    $this->actingAs(userWithPermissions('manage-backups', 'view-app-key'))->get('/setup/system')
        ->assertInertia(fn ($page) => $page->where('canViewAppKey', true));
    $this->actingAs(userWithPermissions('admin-system'))->get('/setup/system')
        ->assertInertia(fn ($page) => $page->where('canAdminSystem', true)->where('canManageBackups', false)->where('runtime', 'systemd'));
    $this->actingAs(userWithPermissions('general-access'))->get('/setup/system')->assertForbidden();
});

test('the old backup files address lands on the merged page', function () {
    $this->actingAs(userWithPermissions('manage-backups'))->get('/setup/backups')->assertRedirect('/setup/system');
});

test('only a dump that ends with the end-of-dump marker counts as complete', function () {
    $tools = app(DatabaseTools::class);
    $write = fn (string $sql) => tap($this->tmp.'/d.sql', fn ($f) => file_put_contents($f, $sql));

    expect($tools->dumpIsComplete($write("CREATE TABLE t (id int);\n\n-- Dump completed on 2026-10-20 10:12:46\n")))->toBeTrue()
        ->and($tools->dumpIsComplete($write("CREATE TABLE t (id int);\nINSERT INTO t VALUES (1),(2")))->toBeFalse()
        ->and($tools->dumpIsComplete($write('')))->toBeFalse()
        ->and($tools->dumpIsComplete($this->tmp.'/missing.sql'))->toBeFalse()
        // the marker mid-file, followed by more, is not an ending
        ->and($tools->dumpIsComplete($write("-- Dump completed on 2026-10-20 10:12:46\nINSERT INTO t VALUES (1),(2")))->toBeFalse();

    // and a real dump has it
    $real = $this->tmp.'/real.sql';
    $process = $tools->process($tools->dumpCommand($real));
    $process->run();
    expect($tools->dumpIsComplete($real))->toBeTrue();
});

test('a scheduled (tier) backup can be downloaded by manage-backups holders, and nothing else is reachable', function () {
    $dir = $this->tmp.'/tiers';
    config(['system.backup_dir' => $dir]);
    mkdir("$dir/daily", 0777, true);
    mkdir("$dir/hourly/20260101-010000", 0777, true);               // an old-style directory
    file_put_contents("$dir/daily/20260102-030000.zip", 'zip-bytes');
    file_put_contents("$dir/secret.zip", 'not in a tier');

    $admin = userWithPermissions('manage-backups');
    $get = fn ($path) => test()->actingAs($admin)->get("/json/backups/tier/{$path}/download");

    $ok = $get('daily/20260102-030000')->assertOk()->assertDownload('relief-backup-daily-20260102-030000.zip');
    expect($ok->headers->get('Cache-Control'))->toContain('no-store');

    $get('daily/20260109-030000')->assertNotFound();                // no such backup
    $get('hourly/20260101-010000')->assertNotFound();               // a directory, not a zip
    $get('weekly/20260102-030000')->assertNotFound();               // not a tier
    $get('daily/..%2Fsecret')->assertNotFound();                    // the stamp is a fixed pattern
    $get('daily/20260102-030000.zip')->assertNotFound();

    // the same permission as an on-demand zip, not admin-system's
    $this->actingAs(userWithPermissions('admin-system'))->get('/json/backups/tier/daily/20260102-030000/download')->assertForbidden();
    $this->actingAs(userWithPermissions('general-access'))->get('/json/backups/tier/daily/20260102-030000/download')->assertForbidden();

    // a Docker install has no tiers to serve
    config(['system.runtime' => 'docker']);
    $get('daily/20260102-030000')->assertNotFound();
});

// ---- per warehouse, one at a time (MULTI_WAREHOUSE.md §10/§11) ----------------------------------------

test('each warehouse backs up into a folder of its own', function () {
    $other = SecondWarehouse::make($this, ['display_name' => 'Elsewhere'])->fresh();
    $here = runBackup('database');
    $there = app(WarehouseContext::class)->run($other, fn () => runBackup('database'));
    app(WarehouseContext::class)->switchTo(Warehouse::default());

    expect($here->status)->toBe('complete', $here->error ?? '')->and($there->status)->toBe('complete', $there->error ?? '')
        ->and($here->path())->toContain('/warehouses/'.Warehouse::default()->id.'/')
        ->and(is_file($here->path()))->toBeTrue()
        ->and(app(WarehouseContext::class)->run($other, fn () => $there->path()))->toContain('/warehouses/'.$other->id.'/')
        ->and(glob($this->tmp.'/out/relief-backup-*.zip'))->toBe([]);
});

test('backups wait for each other across the whole instance, and give up after the limit', function () {
    config(['backup.lock_wait_seconds' => 1]);
    $lock = fopen($this->tmp.'/out/.instance-backup.lock', 'c');
    flock($lock, LOCK_EX);

    $backup = runBackup('database');
    expect($backup->status)->toBe('failed')->and($backup->error)->toContain('kept the instance busy');
    expect(fn () => app(BackupService::class)->createIn($this->tmp.'/elsewhere', 'database'))->toThrow(RuntimeException::class, 'kept the instance busy');

    flock($lock, LOCK_UN);
    expect(runBackup('database')->status)->toBe('complete');
});

test('a dump keeps the structure of the live-state tables but not their rows, and every table on the list exists', function () {
    foreach (config('backup.live_state_tables') as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is on backup.live_state_tables but doesn't exist");
    }

    $db = config('database.connections.'.config('database.default').'.database');
    $tools = app(DatabaseTools::class);
    $command = implode(' ', $tools->dumpCommand($this->tmp.'/x.sql'));

    expect($command)->toContain("--ignore-table-data={$db}.sessions")->toContain("--ignore-table-data={$db}.jobs")
        ->and($command)->not->toContain('--ignore-table=')->not->toContain('--ignore-table-data='.$db.'.people');
});

test('a restore empties the live-state tables, which also covers older backups', function () {
    DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => time()]);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    (fn () => $this->clearMachineLocalState())->call(app(RestoreService::class));

    expect(DB::table('sessions')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

test('the daily backup is made once a day per warehouse', function () {
    $this->artisan('backup:scheduled')->assertSuccessful();
    expect(Backup::count())->toBe(1);

    $this->artisan('backup:scheduled')->assertSuccessful()->expectsOutputToContain('already made');
    expect(Backup::count())->toBe(1);

    $this->travel(1)->days();
    $this->artisan('backup:scheduled')->assertSuccessful();
    expect(Backup::count())->toBe(2);
    $this->travelBack();
});

test('the zips of the one warehouse that predates per-warehouse folders move into its own', function () {
    $warehouse = Warehouse::default();
    file_put_contents($this->tmp.'/out/relief-backup-20260101-000000-database-1.zip', 'zip');
    file_put_contents($this->tmp.'/out/relief-backup-20260101-000000-database-premigrate.zip', 'zip');
    file_put_contents($this->tmp.'/out/someone-elses-notes.zip', 'keep me');

    $done = app(WarehouseLayout::class)->ensure($warehouse);

    $folder = $this->tmp.'/out/warehouses/'.$warehouse->id;
    expect($done)->toContain('moved its backup zips into its own backup folder')
        ->and(is_file($folder.'/relief-backup-20260101-000000-database-1.zip'))->toBeTrue()
        ->and(is_file($folder.'/relief-backup-20260101-000000-database-premigrate.zip'))->toBeTrue()
        ->and(is_file($this->tmp.'/out/someone-elses-notes.zip'))->toBeTrue()
        ->and(glob($this->tmp.'/out/relief-backup-*.zip'))->toBe([]);
});

test('warehouse:resume puts a warehouse a failed restore left unavailable back in service', function () {
    $warehouse = Warehouse::default();
    $this->artisan('warehouse:resume', ['warehouse' => $warehouse->id])->assertFailed();

    $warehouse->forceFill(['status' => 'restoring'])->save();
    $this->artisan('warehouse:resume', ['warehouse' => $warehouse->id])->assertSuccessful();
    expect($warehouse->fresh()->status)->toBe('active');
});
