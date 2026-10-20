<?php

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Support\Facades\Process;

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

function runBackup(bool $uploads): Backup
{
    $backup = Backup::create(['status' => 'queued', 'include_uploads' => $uploads]);
    app(BackupService::class)->run($backup);

    return $backup->fresh();
}

function zipEntries(Backup $backup): array
{
    $zip = new ZipArchive;
    $zip->open($backup->path());
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $contents = fn ($name) => $zip->getFromName($name);

    return [$names, $manifest, $contents];
}

test('a database-only backup holds a verifiable dump and manifest', function () {
    $backup = runBackup(false);

    expect($backup->status)->toBe('complete')->and($backup->error)->toBeNull()
        ->and($backup->expires_at)->not->toBeNull();
    [$names, $manifest, $contents] = zipEntries($backup);

    expect($names)->toContain('manifest.json', 'database/db.sql')
        ->and($manifest['format'])->toBe(1)
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
    file_put_contents($this->tmp.'/public/logo.png', 'png');

    $backup = runBackup(true);
    [$names, $manifest, $contents] = zipEntries($backup);

    expect($names)->toContain('files/private/receiving-photos/a.jpg', 'files/public/logo.png')
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

test('only one backup can be started at a time', function () {
    Process::fake();
    $service = app(BackupService::class);

    $first = $service->start(null, false);
    expect($first->status)->toBe('queued');
    Process::assertRan(fn ($p) => str_contains($p->command, "backup:run {$first->id}") && str_contains($p->command, 'setsid'));

    expect(fn () => $service->start(null, false))->toThrow(RuntimeException::class, 'already running');
});

test('a second runner cannot take the lock while one is running', function () {
    $lock = fopen($this->tmp.'/out/.backup.lock', 'c');
    flock($lock, LOCK_EX);

    $backup = runBackup(false);
    expect($backup->status)->toBe('failed')->and($backup->error)->toContain('already running');

    flock($lock, LOCK_UN);
});

test('a running backup whose process died is marked failed instead of blocking forever', function () {
    $stuck = Backup::create(['status' => 'running', 'include_uploads' => false]);

    app(BackupService::class)->reapInterrupted();

    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->error)->toContain('stopped');
});

test('a running backup that still holds the lock is left alone', function () {
    $running = Backup::create(['status' => 'running', 'include_uploads' => false]);
    $lock = fopen($this->tmp.'/out/.backup.lock', 'c');
    flock($lock, LOCK_EX);

    app(BackupService::class)->reapInterrupted();

    expect($running->fresh()->status)->toBe('running');
    flock($lock, LOCK_UN);
});

test('expired backups are pruned with their files', function () {
    $backup = runBackup(false);
    expect(is_file($backup->path()))->toBeTrue();

    $backup->update(['expires_at' => now()->subMinute()]);
    app(BackupService::class)->prune();

    expect(is_file($backup->path()))->toBeFalse()->and(Backup::find($backup->id))->toBeNull();
});

test('backup endpoints require manage-backups', function () {
    $this->actingAs(userWithPermissions('general-access'))->getJson('/json/backups')->assertForbidden();
    $this->actingAs(userWithPermissions('admin-system'))->postJson('/json/backups')->assertForbidden();
    $this->actingAs(userWithPermissions('manage-backups'))->getJson('/json/backups')->assertOk();
});

test('the admin can start, download and delete a backup', function () {
    Process::fake();
    $admin = userWithPermissions('manage-backups');

    $started = $this->actingAs($admin)->postJson('/json/backups', ['include_uploads' => true])->assertStatus(202);
    expect($started->json('record.include_uploads'))->toBeTrue()
        ->and(Backup::first()->requested_by)->toBe($admin->id);
    $this->actingAs($admin)->postJson('/json/backups')->assertStatus(409);

    $this->actingAs($admin)->deleteJson('/json/backups/'.Backup::first()->id)->assertStatus(409); // still running

    $done = runBackup(false);
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

test('the backups page only offers the key panel to people who can view it', function () {
    $this->actingAs(userWithPermissions('manage-backups'))->get('/setup/backups')
        ->assertInertia(fn ($page) => $page->component('Backups')->where('canViewAppKey', false));
    $this->actingAs(userWithPermissions('manage-backups', 'view-app-key'))->get('/setup/backups')
        ->assertInertia(fn ($page) => $page->where('canViewAppKey', true));
});
