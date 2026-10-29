<?php

// The scheduled whole-instance backup: when it is due (InstanceBackupSchedule), what the provisioner makes and keeps
// (InstanceBackupService::scheduled / pruneScheduled), the fixed requests it accepts, the scheduler's command, and the Instance Admin
// endpoints. The zip itself needs the database administrator and is covered by InstanceBackupTest; here `create` is stubbed.

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceBackupSchedule;
use App\Models\Control\ProvisioningCommand;
use App\Services\InstanceBackupService;
use App\Services\Warehouses\Provisioner;
use App\Services\Warehouses\ProvisioningClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-instance-sched-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/instance', 0777, true);
    config(['backup.dir' => $this->tmp, 'system.runtime' => 'docker']);
    InstanceBackupSchedule::query()->delete();
    ProvisioningCommand::whereIn('command', ['backup-sched', 'backup-now', 'backup-now-files'])->delete();
    $this->accounts = [];

    $this->admin = function (bool $instanceAdmin = true) {
        $user = userWithPermissions('admin-system');
        $account = new Account(['email' => 'ibk-'.Str::random(6).'@example.org']);
        $account->forceFill(['password' => Hash::make('x-password-1'), 'email_verified_at' => now(), 'is_instance_admin' => $instanceAdmin])->save();
        $user->forceFill(['account_id' => $account->id])->save();
        $this->accounts[] = $account;

        return $user->fresh();
    };
    $this->zip = fn (string $name, ?int $mtime = null) => tap($this->tmp.'/instance/'.$name, fn ($path) => file_put_contents($path, 'zip'));
});

afterEach(function () {
    InstanceBackupSchedule::query()->delete();
    ProvisioningCommand::whereIn('command', ['backup-sched', 'backup-now', 'backup-now-files'])->delete();
    foreach ($this->accounts as $account) {
        $account->delete();
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
    $this->travelBack();
});

const SCHEDULE = [
    'enabled' => true, 'daily_hour' => 3, 'daily_keep' => 7, 'daily_with_files' => false,
    'weekly_enabled' => true, 'weekly_dow' => 0, 'weekly_keep' => 4, 'weekly_with_files' => true,
];

// 2026-02-01 is a Sunday, 02-08 the next one. (Not March 8: that day has no 02:00 in a US time zone.)

test('nothing is due until an instance administrator turns the schedule on', function () {
    $schedule = InstanceBackupSchedule::current();
    expect($schedule->enabled)->toBeFalse()->and($schedule->due(Carbon::parse('2026-02-03 12:00')))->toBe([]);

    $schedule->update(['enabled' => true, 'daily_hour' => 3]);
    expect($schedule->due(Carbon::parse('2026-02-03 02:30')))->toBe([])           // before the hour
        ->and($schedule->due(Carbon::parse('2026-02-03 03:05')))->toBe(['daily']);
});

test('a daily backup is due once a day, and a missed one at the next check', function () {
    $schedule = InstanceBackupSchedule::current();
    $schedule->update(['enabled' => true, 'daily_hour' => 3, 'last_daily_at' => Carbon::parse('2026-02-03 03:10')]);

    expect($schedule->due(Carbon::parse('2026-02-03 18:00')))->toBe([])
        ->and($schedule->due(Carbon::parse('2026-02-04 02:00')))->toBe([])
        ->and($schedule->due(Carbon::parse('2026-02-05 15:00')))->toBe(['daily']);   // the machine was off on the 4th
});

test('the weekly backup is due on its day or once over a week old, and asks for files only when its tier does', function () {
    $schedule = InstanceBackupSchedule::current();
    $schedule->update(SCHEDULE + ['last_daily_at' => Carbon::parse('2026-01-31 03:10')]);

    expect($schedule->due(Carbon::parse('2026-02-01 03:05')))->toBe(['daily', 'weekly'])   // a Sunday, never made
        ->and($schedule->withFiles(['daily']))->toBeFalse()
        ->and($schedule->withFiles(['daily', 'weekly']))->toBeTrue();

    $schedule->update(['last_daily_at' => Carbon::parse('2026-02-01 03:10'), 'last_weekly_at' => Carbon::parse('2026-02-01 03:10')]);
    expect($schedule->due(Carbon::parse('2026-02-04 03:05')))->toBe(['daily'])
        ->and($schedule->due(Carbon::parse('2026-02-08 03:05')))->toBe(['daily', 'weekly'])
        ->and($schedule->due(Carbon::parse('2026-02-10 03:05')))->toBe(['daily', 'weekly']);   // the 8th was missed, and 9 days is over a week
});

test('the keep counts are clamped because the provisioner deletes zips by them', function () {
    $schedule = InstanceBackupSchedule::current();
    $schedule->forceFill(['daily_keep' => 0, 'weekly_keep' => 0])->save();
    expect($schedule->keepDaily())->toBe(1)->and($schedule->keepWeekly())->toBe(1);
    $schedule->forceFill(['daily_keep' => 9999, 'weekly_keep' => 9999])->save();
    expect($schedule->keepDaily())->toBe(90)->and($schedule->keepWeekly())->toBe(52);
});

test('the listing reads names only, newest first, and ignores files that are not instance backups', function () {
    ($this->zip)('relief-instance-20260201-030500-full-scheduled-weekly.zip');
    ($this->zip)('relief-instance-20260203-030500-database-scheduled-daily.zip');
    ($this->zip)('relief-instance-20260101-000000-database.zip');
    ($this->zip)('relief-backup-20260201-030500-database-1.zip');   // a warehouse zip
    ($this->zip)('notes.txt');

    $rows = app(InstanceBackupService::class)->listing();

    expect(array_column($rows, 'name'))->toBe([
        'relief-instance-20260203-030500-database-scheduled-daily.zip',
        'relief-instance-20260201-030500-full-scheduled-weekly.zip',
        'relief-instance-20260101-000000-database.zip',
    ])->and($rows[0])->toMatchArray(['scope' => 'database', 'label' => 'scheduled-daily'])
        ->and($rows[1]['scope'])->toBe('full')
        ->and($rows[2]['label'])->toBeNull()
        ->and(InstanceBackupService::isBackupName('relief-instance-20260101-000000-database.zip'))->toBeTrue()
        ->and(InstanceBackupService::isBackupName('../relief-instance-20260101-000000-database.zip'))->toBeFalse()
        ->and(InstanceBackupService::isBackupName('notes.txt'))->toBeFalse();
});

test('pruning keeps the newest daily ones (a weekly counts) and the newest weekly ones, and touches nothing else', function () {
    InstanceBackupSchedule::current()->update(['daily_keep' => 2, 'weekly_keep' => 1]);
    $names = [];
    foreach ([1, 2, 3, 4, 5] as $day) {
        $names["d{$day}"] = "relief-instance-2026020{$day}-030000-database-scheduled-daily.zip";
        ($this->zip)($names["d{$day}"]);
    }
    $names['w1'] = 'relief-instance-20260201-040000-full-scheduled-weekly.zip';   // old, but the newest... only weekly after w2 below
    $names['w2'] = 'relief-instance-20260206-040000-full-scheduled-weekly.zip';
    ($this->zip)($names['w1']);
    ($this->zip)($names['w2']);
    $names['manual'] = 'relief-instance-20250101-000000-database-manual.zip';
    $names['pre'] = 'relief-instance-20250102-000000-database-premigrate.zip';
    $names['plain'] = 'relief-instance-20250103-000000-database.zip';
    foreach (['manual', 'pre', 'plain'] as $k) {
        ($this->zip)($names[$k]);
    }

    $deleted = app(InstanceBackupService::class)->pruneScheduled();

    $left = array_map('basename', glob($this->tmp.'/instance/*.zip'));
    // newest two scheduled of either label: w2 (02-06) and d5 (02-05); newest weekly: w2.
    expect($deleted)->toBe(5)
        ->and($left)->toContain($names['w2'], $names['d5'], $names['manual'], $names['pre'], $names['plain'])
        ->and($left)->not->toContain($names['d1'], $names['d2'], $names['d3'], $names['d4'], $names['w1']);
});

test('the provisioner makes only the fixed instance backups, each with fixed arguments', function () {
    $service = Mockery::mock(InstanceBackupService::class);
    $service->shouldReceive('premigrate')->once()->andReturn(['pre']);
    $service->shouldReceive('scheduled')->once()->andReturn(['sched']);
    $service->shouldReceive('manual')->with(false)->once()->andReturn(['now']);
    $service->shouldReceive('manual')->with(true)->once()->andReturn(['now+files']);
    app()->instance(InstanceBackupService::class, $service);
    $provisioner = (new ReflectionClass(Provisioner::class))->newInstanceWithoutConstructor();

    $results = array_map(fn ($c) => $provisioner->run(new ProvisioningCommand(['command' => $c])), ['instance-backup', 'backup-sched', 'backup-now', 'backup-now-files']);

    expect($results)->toBe([['pre'], ['sched'], ['now'], ['now+files']])
        ->and(ProvisioningCommand::INSTANCE_COMMANDS)->toContain('backup-sched', 'backup-now', 'backup-now-files');
    expect(fn () => $provisioner->run(new ProvisioningCommand(['command' => 'backup-everything'])))->toThrow(RuntimeException::class);
});

test('a scheduled backup does nothing when nothing is due, and otherwise records what it made', function () {
    $service = Mockery::mock(InstanceBackupService::class)->makePartial();
    app()->instance(InstanceBackupService::class, $service);
    $schedule = InstanceBackupSchedule::current();
    $schedule->update(SCHEDULE);

    $this->travelTo(Carbon::parse('2026-02-04 01:00'));   // before the hour
    $service->shouldNotReceive('create');
    expect($service->scheduled())->toBe(['No instance backup is due.']);

    $service->shouldReceive('create')->once()->with($service->dir(), 'scheduled-daily', false, null)
        ->andReturn(['path' => $service->dir().'/relief-instance-20260204-030500-database-scheduled-daily.zip', 'size' => 1, 'schemas' => [], 'warehouses' => 1]);
    $this->travelTo(Carbon::parse('2026-02-04 03:05'));
    $lines = $service->scheduled();

    $schedule->refresh();
    expect($lines[0])->toContain('scheduled instance backup written')
        ->and($schedule->last_status)->toBe('complete')
        ->and($schedule->last_daily_at->format('Y-m-d'))->toBe('2026-02-04')
        ->and($schedule->last_weekly_at)->toBeNull()
        ->and($schedule->due(Carbon::parse('2026-02-04 09:00')))->toBe([])
        ->and(InstanceAuditLog::where('action', 'instance.backup_made')->exists())->toBeTrue();
});

test('a Sunday backup is the weekly one with files, and counts as that day\'s daily one', function () {
    $service = Mockery::mock(InstanceBackupService::class)->makePartial();
    app()->instance(InstanceBackupService::class, $service);
    $schedule = InstanceBackupSchedule::current();
    $schedule->update(SCHEDULE);
    $service->shouldReceive('create')->once()->with($service->dir(), 'scheduled-weekly', true, null)
        ->andReturn(['path' => $service->dir().'/x.zip', 'size' => 1, 'schemas' => [], 'warehouses' => 1]);

    $this->travelTo(Carbon::parse('2026-02-01 03:05'));
    $service->scheduled();

    $schedule->refresh();
    expect($schedule->last_weekly_at)->not->toBeNull()->and($schedule->last_daily_at)->not->toBeNull()
        ->and($schedule->due(Carbon::parse('2026-02-01 20:00')))->toBe([]);
});

test('a failed scheduled backup is recorded, thrown, and stays due for the next hour', function () {
    $service = Mockery::mock(InstanceBackupService::class)->makePartial();
    app()->instance(InstanceBackupService::class, $service);
    $schedule = InstanceBackupSchedule::current();
    $schedule->update(['enabled' => true, 'daily_hour' => 3]);
    $service->shouldReceive('create')->once()->andThrow(new RuntimeException('disk full'));

    $this->travelTo(Carbon::parse('2026-02-04 03:05'));
    expect(fn () => $service->scheduled())->toThrow(RuntimeException::class, 'disk full');

    $schedule->refresh();
    expect($schedule->last_status)->toBe('failed')->and($schedule->last_message)->toBe('disk full')
        ->and($schedule->last_daily_at)->toBeNull()
        ->and($schedule->due(Carbon::parse('2026-02-04 04:05')))->toBe(['daily'])
        ->and(InstanceAuditLog::where('action', 'instance.backup_failed')->exists())->toBeTrue();
});

test('instance:backup --scheduled asks the provisioner only when something is due', function () {
    $client = Mockery::mock(ProvisioningClient::class);
    app()->instance(ProvisioningClient::class, $client);

    $client->shouldNotReceive('request');
    $this->artisan('instance:backup', ['--scheduled' => true])->assertSuccessful()->expectsOutputToContain('No instance backup is due');

    InstanceBackupSchedule::current()->update(['enabled' => true, 'daily_hour' => 0]);
    $client->shouldReceive('request')->once()->with(null, 'backup-sched', 'scheduler', 3600)->andReturn(['scheduled instance backup written: x']);
    $this->artisan('instance:backup', ['--scheduled' => true])->assertSuccessful()->expectsOutputToContain('written');
});

test('only an instance administrator reaches the instance backup endpoints', function () {
    $this->getJson('/json/instance/backups')->assertUnauthorized();
    $this->actingAs(($this->admin)(false))->getJson('/json/instance/backups')->assertForbidden();
    $this->actingAs(($this->admin)(true))->getJson('/json/instance/backups')->assertOk()->assertJsonPath('available', true);
});

test('the page says it is unavailable without the Docker provisioner', function () {
    config(['system.runtime' => 'systemd']);
    $admin = ($this->admin)(true);

    $this->actingAs($admin)->getJson('/json/instance/backups')->assertOk()->assertJsonPath('available', false);
    $this->actingAs($admin)->postJson('/json/instance/backups', ['files' => false, 'password' => 'x-password-1'])->assertStatus(422);
    expect(ProvisioningCommand::where('command', 'backup-now')->exists())->toBeFalse();
});

test('saving the schedule needs the password and sane numbers, and is audited', function () {
    $admin = ($this->admin)(true);

    $this->actingAs($admin)->putJson('/json/instance/backups/schedule', SCHEDULE)->assertStatus(422)->assertJsonValidationErrors('password');
    $this->actingAs($admin)->putJson('/json/instance/backups/schedule', SCHEDULE + ['password' => 'wrong'])->assertStatus(422);
    expect(InstanceBackupSchedule::current()->enabled)->toBeFalse();

    foreach ([['daily_hour', 24], ['daily_keep', 0], ['daily_keep', 91], ['weekly_dow', 7], ['weekly_keep', 53]] as [$field, $value]) {
        $this->actingAs($admin)->putJson('/json/instance/backups/schedule', [$field => $value] + SCHEDULE + ['password' => 'x-password-1'])
            ->assertStatus(422)->assertJsonValidationErrors($field);
    }

    $this->actingAs($admin)->putJson('/json/instance/backups/schedule', SCHEDULE + ['password' => 'x-password-1'])->assertOk()->assertJsonPath('schedule.weekly_keep', 4);
    expect(InstanceBackupSchedule::current()->enabled)->toBeTrue()
        ->and(InstanceBackupSchedule::current()->weekly_with_files)->toBeTrue()
        ->and(InstanceAuditLog::where('action', 'instance.backup_schedule_changed')->exists())->toBeTrue()
        ->and(InstanceAuditLog::where('action', 'instance.password_refused')->exists())->toBeTrue();
});

test('Back up now writes one fixed request for the provisioner, refuses a second while one is waiting, and needs the password', function () {
    $admin = ($this->admin)(true);

    $this->actingAs($admin)->postJson('/json/instance/backups', ['files' => false, 'password' => 'wrong'])->assertStatus(422);
    expect(ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files'])->count())->toBe(0);

    $this->actingAs($admin)->postJson('/json/instance/backups', ['files' => true, 'password' => 'x-password-1'])->assertOk();
    $row = ProvisioningCommand::where('command', 'backup-now-files')->firstOrFail();
    expect($row->status)->toBe('pending')->and($row->warehouse_id)->toBeNull()->and($row->requested_by)->toBe($admin->email);

    $this->actingAs($admin)->postJson('/json/instance/backups', ['files' => false, 'password' => 'x-password-1'])->assertStatus(422);
    expect(ProvisioningCommand::whereIn('command', ['backup-now', 'backup-now-files'])->count())->toBe(1);

    $this->actingAs($admin)->getJson('/json/instance/backups')->assertJsonPath('working', true);
    $row->forceFill(['status' => 'done', 'message' => 'instance backup written: x', 'finished_at' => now()])->save();
    $this->actingAs($admin)->getJson('/json/instance/backups')->assertJsonPath('working', false)->assertJsonPath('activity.status', 'done');
});

test('a zip is downloaded only by its exact name, only when the web server can read it, and the download is audited', function () {
    $admin = ($this->admin)(true);
    ($this->zip)('relief-instance-20260204-030500-database-scheduled-daily.zip');
    $name = 'relief-instance-20260204-030500-database-scheduled-daily.zip';

    $list = $this->actingAs($admin)->getJson('/json/instance/backups')->assertOk();
    expect($list->json('backups.0.name'))->toBe($name)->and($list->json('backups.0.downloadable'))->toBeTrue();

    $this->actingAs($admin)->get("/json/instance/backups/{$name}/download")->assertOk()->assertDownload($name);
    expect(InstanceAuditLog::where('action', 'instance.backup_downloaded')->exists())->toBeTrue();

    $this->actingAs($admin)->get('/json/instance/backups/notes.txt/download')->assertNotFound();
    $this->actingAs($admin)->get('/json/instance/backups/relief-instance-20990101-000000-database.zip/download')->assertNotFound();
    $this->actingAs(($this->admin)(false))->get("/json/instance/backups/{$name}/download")->assertForbidden();

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {   // root reads anything
        chmod($this->tmp.'/instance/'.$name, 0000);
        $this->actingAs($admin)->get("/json/instance/backups/{$name}/download")->assertForbidden();
        expect($this->actingAs($admin)->getJson('/json/instance/backups')->json('backups.0.downloadable'))->toBeFalse();
    }
});
