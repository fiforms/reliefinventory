<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\User;

test('the system admin page requires the admin-system permission', function () {
    $this->actingAs(User::factory()->create())
        ->get('/setup/system')
        ->assertForbidden();
});

test('a user with admin-system can load the system admin page', function () {
    $this->actingAs(userWithPermissions('admin-system'))
        ->get('/setup/system')
        ->assertOk();
});

test('system json endpoints require the admin-system permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/json/system/status')->assertForbidden();
    $this->actingAs($user)->post('/json/system/update')->assertForbidden();
    $this->actingAs($user)->post('/json/system/backup')->assertForbidden();
    $this->actingAs($user)->put('/json/system/backup-settings')->assertForbidden();
});

test('status returns version, backups, and settings for a system admin', function () {
    config(['system.settings_file' => sys_get_temp_dir().'/ri-test-backup-settings.conf']);
    @unlink(config('system.settings_file'));

    $response = $this->actingAs(userWithPermissions('admin-system'))
        ->get('/json/system/status');

    $response->assertOk()->assertJsonStructure([
        'version' => ['current', 'behind', 'pending_commits'],
        'backup_settings' => ['frequency', 'hour', 'tz', 'keep_hourly', 'keep_daily', 'keep_monthly', 'keep_yearly'],
        'backups' => ['tiers' => ['hourly', 'daily', 'monthly', 'yearly'], 'last_scheduled'],
        'timezones',
    ]);
    // No settings file yet -> defaults
    expect($response->json('backup_settings.frequency'))->toBe('daily')
        ->and($response->json('backup_settings.hour'))->toBe(2);
});

test('backup entries are ordered by actual mtime, not by stamp-name sort order', function () {
    // Simulates the exact scenario a BACKUP_TZ change produces: an
    // older-named directory that was actually touched more recently than a
    // newer-named one. A name-only sort would report the wrong one as latest.
    $dir = sys_get_temp_dir().'/ri-test-backups-'.uniqid();
    config(['system.backup_dir' => $dir]);
    mkdir("$dir/daily", 0777, true);
    mkdir("$dir/daily/20260827-070910");
    mkdir("$dir/daily/20260827-001538");
    touch("$dir/daily/20260827-070910", strtotime('2026-08-27 07:09:11 UTC'));
    touch("$dir/daily/20260827-001538", strtotime('2026-08-27 07:15:38 UTC'));

    $response = $this->actingAs(userWithPermissions('admin-system'))
        ->get('/json/system/status');

    $response->assertOk()
        ->assertJsonPath('backups.tiers.daily.entries.0', '20260827-001538')
        ->assertJsonPath('backups.tiers.daily.entries.1', '20260827-070910');
});

test('backup settings round-trip through the conf file', function () {
    config(['system.settings_file' => sys_get_temp_dir().'/ri-test-backup-settings.conf']);
    @unlink(config('system.settings_file'));

    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->put('/json/system/backup-settings', [
        'frequency' => 'weekly',
        'hour' => 3,
        'dow' => 1,
        'tz' => 'America/New_York',
        'keep_hourly' => 48,
        'keep_daily' => 7,
        'keep_monthly' => 6,
        'keep_yearly' => 2,
    ])->assertOk()->assertJsonPath('backup_settings.frequency', 'weekly');

    // The file matches the KEY=value format scripts/update.sh parses
    $contents = file_get_contents(config('system.settings_file'));
    expect($contents)->toContain('BACKUP_FREQUENCY=weekly')
        ->toContain('BACKUP_HOUR=3')
        ->toContain('BACKUP_TZ=America/New_York')
        ->toContain('KEEP_HOURLY=48')
        ->toContain('KEEP_DAILY=7');

    // And reads back through the status endpoint
    $this->actingAs($admin)->get('/json/system/status')
        ->assertJsonPath('backup_settings.dow', 1)
        ->assertJsonPath('backup_settings.keep_yearly', 2);

    @unlink(config('system.settings_file'));
});

test('a stale running status is surfaced as stalled instead of blocking retry forever', function () {
    $statusFile = sys_get_temp_dir().'/ri-test-update-status.json';
    config(['system.update_status_file' => $statusFile]);

    // Simulate the exact failure mode this guards against: the panel wrote
    // "requested" but the systemd unit never actually started (e.g. a
    // misconfigured SYSTEM_UPDATE_UNIT), so update.sh never got a chance
    // to overwrite this with real progress.
    file_put_contents($statusFile, json_encode([
        'state' => 'running',
        'message' => 'Update requested; waiting for the updater to start',
        'updated_at' => now()->subMinutes(10)->toIso8601String(),
    ]));

    $admin = userWithPermissions('admin-system');

    $status = $this->actingAs($admin)->getJson('/json/system/status');
    $status->assertOk()->assertJsonPath('update_status.state', 'stalled');
    expect($status->json('update_status.message'))->toContain('never started');

    // And a retry is no longer blocked by the false "already running" 409
    // (whether the actual systemctl call succeeds depends on the sandbox
    // having sudo/systemd available, which isn't the point of this test).
    expect($this->actingAs($admin)->postJson('/json/system/update')->status())->not->toBe(409);

    @unlink($statusFile);
});

test('a genuinely recent running status still blocks a new update request', function () {
    $statusFile = sys_get_temp_dir().'/ri-test-update-status-2.json';
    config(['system.update_status_file' => $statusFile]);

    file_put_contents($statusFile, json_encode([
        'state' => 'running',
        'message' => 'Update running (started just now)',
        'updated_at' => now()->toIso8601String(),
    ]));

    $this->actingAs(userWithPermissions('admin-system'))
        ->postJson('/json/system/update')
        ->assertStatus(409);

    @unlink($statusFile);
});

test('invalid backup settings are rejected', function () {
    config(['system.settings_file' => sys_get_temp_dir().'/ri-test-backup-settings.conf']);

    $this->actingAs(userWithPermissions('admin-system'))
        ->putJson('/json/system/backup-settings', [
            'frequency' => 'hourly',       // not offered
            'hour' => 25,                  // out of range
            'dow' => 7,
            'tz' => 'Not/AZone',
            'keep_hourly' => 0,            // must keep at least one hourly
            'keep_daily' => 0,             // must keep at least one daily
            'keep_monthly' => 12,
            'keep_yearly' => 3,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency', 'hour', 'tz', 'keep_hourly', 'keep_daily']);
});
