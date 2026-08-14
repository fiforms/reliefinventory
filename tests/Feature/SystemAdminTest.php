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
        'backup_settings' => ['frequency', 'hour', 'tz', 'keep_daily', 'keep_monthly', 'keep_yearly'],
        'backups' => ['tiers' => ['daily', 'monthly', 'yearly'], 'last_scheduled'],
        'timezones',
    ]);
    // No settings file yet -> defaults
    expect($response->json('backup_settings.frequency'))->toBe('daily')
        ->and($response->json('backup_settings.hour'))->toBe(2);
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
        'keep_daily' => 7,
        'keep_monthly' => 6,
        'keep_yearly' => 2,
    ])->assertOk()->assertJsonPath('backup_settings.frequency', 'weekly');

    // The file matches the KEY=value format scripts/update.sh parses
    $contents = file_get_contents(config('system.settings_file'));
    expect($contents)->toContain('BACKUP_FREQUENCY=weekly')
        ->toContain('BACKUP_HOUR=3')
        ->toContain('BACKUP_TZ=America/New_York')
        ->toContain('KEEP_DAILY=7');

    // And reads back through the status endpoint
    $this->actingAs($admin)->get('/json/system/status')
        ->assertJsonPath('backup_settings.dow', 1)
        ->assertJsonPath('backup_settings.keep_yearly', 2);

    @unlink(config('system.settings_file'));
});

test('invalid backup settings are rejected', function () {
    config(['system.settings_file' => sys_get_temp_dir().'/ri-test-backup-settings.conf']);

    $this->actingAs(userWithPermissions('admin-system'))
        ->putJson('/json/system/backup-settings', [
            'frequency' => 'hourly',       // not offered
            'hour' => 25,                  // out of range
            'dow' => 7,
            'tz' => 'Not/AZone',
            'keep_daily' => 0,             // must keep at least one daily
            'keep_monthly' => 12,
            'keep_yearly' => 3,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency', 'hour', 'tz', 'keep_daily']);
});
