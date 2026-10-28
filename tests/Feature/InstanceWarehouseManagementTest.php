<?php

// Warehouse management on Instance Admin (InstanceWarehouseController): instance administrators only, every change re-asks for the
// password, and removal from the screen waits 30 days after archiving. These work on plain control rows (no provisioned schema).

use App\Models\Accounts\Account;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->made = [];
    $this->accounts = [];
    $this->person = function (bool $instanceAdmin) {
        $user = userWithPermissions('admin-system');
        $account = new Account(['email' => 'wm-'.Str::random(6).'@example.org']);
        $account->forceFill(['password' => Hash::make('right-password-1'), 'email_verified_at' => now(), 'is_instance_admin' => $instanceAdmin])->save();
        $user->forceFill(['account_id' => $account->id])->save();
        $this->accounts[] = $account;

        return $user->fresh();
    };
    $this->warehouse = fn (string $status = 'active') => $this->made[] = Warehouse::create([
        'schema_name' => 'test_reliefinventory_'.Str::random(6), 'display_name' => 'WM '.Str::random(6), 'status' => $status,
    ]);
    $this->admin = ($this->person)(true);
    // With more than one warehouse, a request that changes something must say which one it was made in (GuardWarehouseTab); the browser always does.
    $this->withHeader('X-RI-Warehouse', Warehouse::default()->id);
});

afterEach(function () {
    foreach ($this->made as $warehouse) {
        InstanceAuditLog::where('warehouse_id', $warehouse->id)->delete();
        $warehouse->delete();
    }
    foreach ($this->accounts as $account) {
        $account->delete();
    }
});

test('only instance administrators can see or change warehouses', function () {
    $w = ($this->warehouse)();
    $plain = ($this->person)(false);

    $this->actingAs($plain)->getJson('/json/instance/warehouses')->assertForbidden();
    $this->actingAs($plain)->postJson("/json/instance/warehouses/{$w->id}/suspend", ['password' => 'right-password-1'])->assertForbidden();
    expect($w->fresh()->status)->toBe('active');
});

test('the list needs no password and carries what the screen shows', function () {
    $w = ($this->warehouse)();

    $row = collect($this->actingAs($this->admin)->getJson('/json/instance/warehouses')->assertOk()->json('warehouses'))->firstWhere('id', $w->id);
    expect($row)->toMatchArray(['name' => $w->display_name, 'status' => 'active', 'can_remove_now' => false]);
});

test('every change needs the right password and does nothing without it', function () {
    $w = ($this->warehouse)();

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/suspend", [])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/suspend", ['password' => 'wrong'])
        ->assertStatus(422)->assertJsonPath('errors.password.0', 'That password is not correct.');
    expect($w->fresh()->status)->toBe('active')
        ->and(InstanceAuditLog::where('action', 'warehouse.password_refused')->where('details->warehouse', $w->id)->exists())->toBeTrue();

    InstanceAuditLog::where('action', 'warehouse.password_refused')->delete();
});

test('suspend, activate and edit work with the password and are audited', function () {
    $w = ($this->warehouse)();
    $ok = ['password' => 'right-password-1'];

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/suspend", $ok + ['reason' => 'testing'])->assertOk();
    expect($w->fresh()->status)->toBe('suspended');
    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/suspend", $ok)->assertStatus(422); // already suspended

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/activate", $ok)->assertOk();
    expect($w->fresh()->status)->toBe('active');

    $this->actingAs($this->admin)->putJson("/json/instance/warehouses/{$w->id}", $ok + ['name' => 'Renamed '.$w->id, 'listed' => false, 'notes' => 'hello'])->assertOk();
    expect($w->fresh())->display_name->toBe('Renamed '.$w->id)->listed->toBeFalse()->notes->toBe('hello');

    expect(InstanceAuditLog::where('warehouse_id', $w->id)->pluck('action')->all())
        ->toContain('warehouse.suspended', 'warehouse.activated', 'warehouse.updated');
});

test('a warehouse name must be unique', function () {
    $a = ($this->warehouse)();
    $b = ($this->warehouse)();

    $this->actingAs($this->admin)->putJson("/json/instance/warehouses/{$b->id}", ['password' => 'right-password-1', 'name' => $a->display_name])
        ->assertStatus(422);
});

test('hostnames can be added and removed', function () {
    $w = ($this->warehouse)();
    $ok = ['password' => 'right-password-1'];
    $host = 'wm-'.Str::lower(Str::random(6)).'.example.org';

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/domains", $ok + ['hostname' => $host])->assertOk();
    expect($w->domains()->where('hostname', $host)->where('is_primary', true)->exists())->toBeTrue();
    $this->actingAs($this->admin)->deleteJson("/json/instance/warehouses/{$w->id}/domains", $ok + ['hostname' => $host])->assertOk();
    expect($w->domains()->count())->toBe(0);
});

test('archiving can be undone, and removal waits 30 days from the screen', function () {
    $w = ($this->warehouse)();
    $ok = ['password' => 'right-password-1'];

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/archive", $ok)->assertOk();
    expect($w->fresh()->status)->toBe('archived');

    $row = fn () => collect($this->actingAs($this->admin)->getJson('/json/instance/warehouses')->json('warehouses'))->firstWhere('id', $w->id);
    expect($row()['can_remove_now'])->toBeFalse();

    // Too soon: refused, and the warehouse is still there.
    $this->actingAs($this->admin)->deleteJson("/json/instance/warehouses/{$w->id}", $ok + ['confirm' => $w->display_name])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'warehouse:remove'));
    expect(Warehouse::find($w->id))->not->toBeNull();

    // After the wait, the typed name is still required.
    $w->forceFill(['archived_at' => now()->subDays(31)])->save();
    expect($row()['can_remove_now'])->toBeTrue();
    $this->actingAs($this->admin)->deleteJson("/json/instance/warehouses/{$w->id}", $ok + ['confirm' => 'not the name'])->assertStatus(422);
    expect(Warehouse::find($w->id))->not->toBeNull();

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$w->id}/archive", $ok + ['undo' => true])->assertOk();
    expect($w->fresh()->status)->toBe('active');
});

test('the default warehouse cannot be archived from the screen', function () {
    $default = Warehouse::default();

    $this->actingAs($this->admin)->postJson("/json/instance/warehouses/{$default->id}/archive", ['password' => 'right-password-1'])->assertStatus(422);
    expect($default->fresh()->status)->toBe('active');
});
