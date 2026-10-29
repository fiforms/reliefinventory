<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\User;
use Illuminate\Support\Facades\Log;

test('the system admin page requires admin-system or manage-backups', function () {
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
});

test('status returns the version and update state, and nothing about backups (they are BackupController\'s now)', function () {
    $response = $this->actingAs(userWithPermissions('admin-system'))->get('/json/system/status');

    $response->assertOk()->assertJsonPath('runtime', 'systemd')->assertJsonStructure([
        'version' => ['current', 'behind', 'pending_commits'], 'update_status', 'offline_mode',
    ]);
    foreach (['backup_settings', 'backups', 'container_backups', 'timezones'] as $gone) {
        expect($response->json())->not->toHaveKey($gone);
    }
});

test('the bare-metal backup panel\'s own endpoints are gone: one schedule, one page', function () {
    $admin = userWithPermissions('admin-system', 'manage-backups');

    $this->actingAs($admin)->postJson('/json/system/backup')->assertNotFound();
    $this->actingAs($admin)->putJson('/json/system/backup-settings', [])->assertNotFound();
    $this->actingAs($admin)->get('/json/backups/tier/daily/20260102-030000/download')->assertNotFound();
    // what replaced them
    $this->actingAs($admin)->getJson('/json/backups/schedule')->assertOk();
});

test('a Docker install says so, has no web updater for a warehouse admin, and still has what applies everywhere', function () {
    config(['system.runtime' => 'docker']);
    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->get('/json/system/status')->assertOk()
        ->assertJsonPath('runtime', 'docker')
        ->assertJsonPath('update_status', null)
        ->assertJsonPath('version.behind', null);

    // Updating is the host-side updater's (InstanceUpdateTest); a warehouse admin who isn't an instance admin may not ask for it.
    $this->actingAs($admin)->post('/json/system/update')->assertStatus(403);
    $this->actingAs($admin)->post('/json/system/check-updates')->assertStatus(403);
    // what applies everywhere still works
    $this->actingAs($admin)->putJson('/json/system/offline-mode', ['enabled' => false])->assertOk();
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

test('offline mode round-trips through the singleton setting and status endpoint', function () {
    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->putJson('/json/system/offline-mode', ['enabled' => true])
        ->assertOk()->assertJsonPath('offline_mode', true);

    $this->actingAs($admin)->getJson('/json/system/status')
        ->assertJsonPath('offline_mode', true);

    $this->actingAs($admin)->putJson('/json/system/offline-mode', ['enabled' => false])
        ->assertOk()->assertJsonPath('offline_mode', false);
});

test('saving offline mode requires the admin-system permission', function () {
    $this->actingAs(User::factory()->create())
        ->putJson('/json/system/offline-mode', ['enabled' => true])
        ->assertForbidden();
});

test('the deprecated bare-metal web updater still runs but logs a deprecation warning', function () {
    config(['system.update_status_file' => sys_get_temp_dir().'/ri-test-update-status-'.bin2hex(random_bytes(3)).'.json']);
    Log::spy();

    // No sudoers rule in the test environment, so starting the unit fails — the point is that the path is still taken.
    $this->actingAs(userWithPermissions('admin-system'))->post('/json/system/update');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Deprecated bare-metal web update'))->once();
    @unlink(config('system.update_status_file'));
});
