<?php

// The Docker self-update flag: the host-side updater's endpoints (shared secret, 404 when off), the request/report
// state machine, and who may ask for an update from the admin page or the command line.

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\InstanceUpdate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['system.update_secret' => 'test-secret-value']);
    InstanceUpdate::query()->delete();
    $this->auth = ['Authorization' => 'Bearer test-secret-value'];
    $this->admin = function (bool $instanceAdmin) {
        $user = userWithPermissions('admin-system');
        $account = new Account(['email' => 'upd-'.Str::random(6).'@example.org']);
        $account->forceFill(['password' => Hash::make('x-password-1'), 'email_verified_at' => now(), 'is_instance_admin' => $instanceAdmin])->save();
        $user->forceFill(['account_id' => $account->id])->save();
        $this->accounts[] = $account;

        return $user->fresh();
    };
    $this->accounts = [];
});

afterEach(function () {
    InstanceUpdate::query()->delete();
    foreach ($this->accounts as $account) {
        $account->delete();
    }
});

test('the updater endpoints do not exist until a secret is configured', function () {
    config(['system.update_secret' => '']);
    $this->getJson('/api/instance/update', $this->auth)->assertNotFound();
    $this->postJson('/api/instance/update/status', ['state' => 'done'], $this->auth)->assertNotFound();
});

test('the updater endpoints refuse a missing or wrong secret', function () {
    $this->getJson('/api/instance/update')->assertUnauthorized();
    $this->getJson('/api/instance/update', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    $this->postJson('/api/instance/update/status', ['state' => 'done'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});

test('a request shows up in the poll once, then a report advances and finishes it', function () {
    $this->getJson('/api/instance/update', $this->auth)->assertOk()->assertJsonPath('action', null);

    InstanceUpdate::request('update', 'someone@example.org');
    $this->getJson('/api/instance/update', $this->auth)->assertJsonPath('action', 'update');

    $this->postJson('/api/instance/update/status', ['state' => 'pulling', 'message' => 'Pulling'], $this->auth)->assertOk()->assertJsonPath('accepted', true);
    // Claimed: a restarted updater must not see it again.
    $this->getJson('/api/instance/update', $this->auth)->assertJsonPath('action', null)->assertJsonPath('state', 'pulling');

    $this->postJson('/api/instance/update/status', ['state' => 'restarting'], $this->auth)->assertOk();
    $this->postJson('/api/instance/update/status', ['state' => 'done', 'current_version' => '1.4.0', 'message' => 'Updated'], $this->auth)->assertOk();

    $row = InstanceUpdate::current();
    expect($row->state)->toBe('done')->and($row->action)->toBeNull()->and($row->current_version)->toBe('1.4.0');
    expect(InstanceAuditLog::where('action', 'update.finished')->latest('id')->first()->details['result'])->toBe('done');
});

test('a report with nothing open is ignored and cannot rewrite a finished run', function () {
    InstanceUpdate::request('check', 'x');
    $this->postJson('/api/instance/update/status', ['state' => 'done', 'available_version' => '1.5.0'], $this->auth)->assertOk();
    $this->postJson('/api/instance/update/status', ['state' => 'failed', 'message' => 'late'], $this->auth)->assertOk()->assertJsonPath('accepted', false);

    $row = InstanceUpdate::current();
    expect($row->state)->toBe('done')->and($row->available_version)->toBe('1.5.0')->and($row->last_checked_at)->not->toBeNull();
});

test('a state the updater may not report is rejected', function () {
    InstanceUpdate::request('check', 'x');
    $this->postJson('/api/instance/update/status', ['state' => 'requested'], $this->auth)->assertUnprocessable();
    $this->postJson('/api/instance/update/status', ['state' => 'idle'], $this->auth)->assertUnprocessable();
});

test('a second request while one runs is refused, but a stalled run can be requested again', function () {
    expect(InstanceUpdate::request('update', 'a'))->not->toBeNull();
    expect(InstanceUpdate::request('check', 'b'))->toBeNull();

    InstanceUpdate::current()->forceFill(['state_changed_at' => now()->subMinutes(InstanceUpdate::STALE_AFTER_MINUTES + 1)])->save();
    expect(InstanceUpdate::current()->payload()['state'])->toBe('stalled');
    expect(InstanceUpdate::request('check', 'b'))->not->toBeNull();
});

test('only an instance administrator can ask for a check or update from the page, and only when enabled', function () {
    config(['system.runtime' => 'docker']);
    $this->actingAs(($this->admin)(false))->postJson('/json/system/update')->assertForbidden();
    $this->actingAs(($this->admin)(false))->postJson('/json/system/check-updates')->assertForbidden();

    $instanceAdmin = ($this->admin)(true);
    config(['system.update_secret' => '']);
    $this->actingAs($instanceAdmin)->postJson('/json/system/update')->assertStatus(409);

    config(['system.update_secret' => 'test-secret-value']);
    $this->actingAs($instanceAdmin)->postJson('/json/system/check-updates')->assertStatus(202)->assertJsonPath('docker_update.state', 'requested');
    $this->actingAs($instanceAdmin)->postJson('/json/system/update')->assertStatus(409);

    expect(InstanceAuditLog::where('action', 'update.requested')->latest('id')->first()->actor)->toBe($instanceAdmin->email);
});

test('status carries the update flag on Docker and the deprecation flag on bare metal', function () {
    $user = ($this->admin)(true);

    $this->actingAs($user)->getJson('/json/system/status')->assertOk()
        ->assertJsonPath('updater_deprecated', true)->assertJsonPath('docker_update', null);

    config(['system.runtime' => 'docker']);
    $this->actingAs($user)->getJson('/json/system/status')->assertOk()
        ->assertJsonPath('updater_deprecated', false)->assertJsonPath('docker_update.enabled', true)
        ->assertJsonPath('docker_update.can_request', true);
});

test('instance:update requests through the flag and refuses when off or busy', function () {
    $this->artisan('instance:update', ['action' => 'apply'])->assertSuccessful();
    expect(InstanceUpdate::current()->action)->toBe('update')->and(InstanceUpdate::current()->requested_by)->toBe('command line');

    $this->artisan('instance:update', ['action' => 'check'])->assertFailed();

    InstanceUpdate::query()->delete();
    config(['system.update_secret' => '']);
    $this->artisan('instance:update', ['action' => 'check'])->assertFailed();
    $this->artisan('instance:update', ['action' => 'status'])->assertSuccessful();
});
