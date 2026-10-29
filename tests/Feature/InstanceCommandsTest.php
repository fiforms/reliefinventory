<?php

// The instance-management commands (`account:*`, `instance:*`, `warehouse:*`): the operator's core set. They work on plain control and accounts
// rows, so none of this needs a provisioned warehouse except warehouse:admin, which joins a login to the (set-up) test warehouse.

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Services\FirstRunService;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-instance-cmds-'.bin2hex(random_bytes(4));
    mkdir($this->tmp);
    $this->emails = [];
    $this->made = [];
    $this->passwordFile = function (string $password) {
        file_put_contents($this->tmp.'/pw', $password."\n");

        return $this->tmp.'/pw';
    };
    $this->account = function (string $local, bool $admin = false, bool $locked = false) {
        $account = new Account(['email' => "{$local}-".Str::random(5).'@example.org']);
        $account->forceFill(['password' => Hash::make('old-password-1'), 'email_verified_at' => now(), 'is_instance_admin' => $admin, 'locked_at' => $locked ? now() : null])->save();
        $this->emails[] = $account->email;

        return $account;
    };
    $this->warehouse = function (string $name, string $status = 'active') {
        return $this->made[] = Warehouse::create(['schema_name' => 'test_reliefinventory_'.Str::random(6), 'display_name' => $name.' '.Str::random(4), 'status' => $status]);
    };
});

afterEach(function () {
    app(WarehouseContext::class)->switchTo(Warehouse::default());
    Account::whereIn('email', $this->emails)->delete();   // memberships go with them
    foreach ($this->made as $warehouse) {
        $warehouse->delete();
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
});

test('account:create makes a verified login (instance admin on request), refuses a duplicate or a weak password, and is audited', function () {
    $email = strtolower('new-'.Str::random(5).'@example.org');
    $this->emails[] = $email;

    $this->artisan('account:create', ['email' => $email, '--instance-admin' => true, '--password-file' => ($this->passwordFile)('a-Strong-passphrase-9'), '--no-interaction' => true])->assertSuccessful();

    $account = Account::findByEmail($email);
    expect($account->is_instance_admin)->toBeTrue()->and($account->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-Strong-passphrase-9', $account->password))->toBeTrue()
        ->and(InstanceAuditLog::where('action', 'account.created')->where('details->email', $email)->exists())->toBeTrue();

    $this->artisan('account:create', ['email' => $email, '--password-file' => ($this->passwordFile)('a-Strong-passphrase-9'), '--no-interaction' => true])->assertFailed();
    $this->artisan('account:create', ['email' => 'weak-'.Str::random(5).'@example.org', '--password-file' => ($this->passwordFile)('abc'), '--no-interaction' => true])->assertFailed();
    $this->artisan('account:create', ['email' => 'not-an-email', '--password-file' => ($this->passwordFile)('a-Strong-passphrase-9'), '--no-interaction' => true])->assertFailed();
    $this->artisan('account:create', ['email' => 'nopass-'.Str::random(5).'@example.org', '--no-interaction' => true])->assertFailed();   // no terminal, no file
});

test('account:password sets the password and ends the login\'s sessions', function () {
    $account = ($this->account)('pw');
    $epoch = $account->auth_epoch;

    $this->artisan('account:password', ['email' => $account->email, '--password-file' => ($this->passwordFile)('another-Strong-pass-7'), '--no-interaction' => true])->assertSuccessful();

    $account->refresh();
    expect(Hash::check('another-Strong-pass-7', $account->password))->toBeTrue()->and($account->auth_epoch)->toBeGreaterThan($epoch)
        ->and(InstanceAuditLog::where('action', 'account.password_set')->where('details->email', $account->email)->exists())->toBeTrue();
    $this->artisan('account:password', ['email' => 'nobody-'.Str::random(5).'@example.org', '--password-file' => ($this->passwordFile)('another-Strong-pass-7'), '--no-interaction' => true])->assertFailed();
});

test('account:lock locks and unlocks, and never the last unlocked instance admin', function () {
    $plain = ($this->account)('plain');
    $this->artisan('account:lock', ['email' => $plain->email])->assertSuccessful();
    expect($plain->fresh()->locked_at)->not->toBeNull();
    $this->artisan('account:lock', ['email' => $plain->email, '--unlock' => true])->assertSuccessful();
    expect($plain->fresh()->locked_at)->toBeNull();

    // With every other instance admin locked, this one cannot be.
    $only = ($this->account)('only', admin: true);
    $others = Account::where('is_instance_admin', true)->whereKeyNot($only->id)->whereNull('locked_at')->pluck('id');
    Account::whereIn('id', $others)->update(['locked_at' => now()]);
    try {
        $this->artisan('account:lock', ['email' => $only->email])->assertFailed();
        expect($only->fresh()->locked_at)->toBeNull();
    } finally {
        Account::whereIn('id', $others)->update(['locked_at' => null]);
    }
});

test('account:instance-admin grants and revokes, with an audit entry and a pointer when the login does not exist', function () {
    $other = ($this->account)('other', admin: true);   // so revoking is never "the only one"
    $account = ($this->account)('promote');

    $this->artisan('account:instance-admin', ['email' => $account->email])->assertSuccessful();
    expect($account->fresh()->is_instance_admin)->toBeTrue();
    $this->artisan('account:instance-admin', ['email' => $account->email, '--revoke' => true])->assertSuccessful();
    expect($account->fresh()->is_instance_admin)->toBeFalse()
        ->and(InstanceAuditLog::whereIn('action', ['account.instance_admin_granted', 'account.instance_admin_revoked'])->where('details->email', $account->email)->count())->toBe(2);

    $this->artisan('account:instance-admin', ['email' => 'nobody-'.Str::random(5).'@example.org'])->assertFailed()->expectsOutputToContain('account:create');
});

test('account:list and account:show tell who is an instance admin, who is locked and where each login belongs', function () {
    $admin = ($this->account)('listed-admin', admin: true);
    $locked = ($this->account)('listed-locked', locked: true);

    $this->artisan('account:list')->expectsOutputToContain($admin->email)->expectsOutputToContain($locked->email)->assertSuccessful();
    $this->artisan('account:list', ['--locked' => true])->expectsOutputToContain($locked->email)->doesntExpectOutputToContain($admin->email)->assertSuccessful();
    $this->artisan('account:show', ['email' => $locked->email])->expectsOutputToContain('Locked:')->assertSuccessful();
});

test('warehouse:suspend and warehouse:activate move only between serving states, point the others to their own way back, and are audited', function () {
    $w = ($this->warehouse)('Suspendable');

    $this->artisan('warehouse:suspend', ['warehouse' => $w->id, '--reason' => 'unpaid'])->assertSuccessful();
    expect($w->fresh()->status)->toBe('suspended')->and(Warehouse::findReachable($w->id))->toBeNull();
    $this->artisan('warehouse:suspend', ['warehouse' => $w->id, '--maintenance' => true])->assertSuccessful();   // suspended -> maintenance
    expect($w->fresh()->status)->toBe('maintenance');
    $this->artisan('warehouse:activate', ['warehouse' => strtolower($w->id)])->assertSuccessful();
    expect($w->fresh()->status)->toBe('active')->and(Warehouse::findReachable($w->id))->not->toBeNull();
    expect(InstanceAuditLog::where('warehouse_id', $w->id)->orderBy('id')->pluck('action')->all())->toBe(['warehouse.suspended', 'warehouse.suspended', 'warehouse.activated'])
        ->and(InstanceAuditLog::where('action', 'warehouse.suspended')->where('warehouse_id', $w->id)->orderBy('id')->first()->details['reason'])->toBe('unpaid');

    foreach (['restoring' => 'warehouse:resume', 'archived' => 'archive', 'awaiting_setup' => 'setup-code', 'provisioning' => '--resume'] as $status => $hint) {
        $other = ($this->warehouse)('Other', $status);
        $this->artisan('warehouse:activate', ['warehouse' => $other->id])->assertFailed()->expectsOutputToContain($hint);
        $this->artisan('warehouse:suspend', ['warehouse' => $other->id])->assertFailed();
        expect($other->fresh()->status)->toBe($status);
    }
    $this->artisan('warehouse:suspend', ['warehouse' => 'ZZZZZZZZ'])->assertFailed();
});

test('warehouse:set renames (names stay unique), changes the contact and chooser listing, and refuses nothing-to-do', function () {
    $a = ($this->warehouse)('Alpha');
    $b = ($this->warehouse)('Beta');

    $this->artisan('warehouse:set', ['warehouse' => $a->id, '--name' => $b->display_name])->assertFailed();
    $this->artisan('warehouse:set', ['warehouse' => $a->id])->assertFailed();
    $this->artisan('warehouse:set', ['warehouse' => $a->id, '--listed' => 'maybe'])->assertFailed();
    $this->artisan('warehouse:set', ['warehouse' => $a->id, '--name' => 'Renamed '.$a->id, '--email' => 'boss@example.org', '--listed' => 'no', '--notes' => 'hello'])->assertSuccessful();

    $a->refresh();
    expect($a->display_name)->toBe('Renamed '.$a->id)->and($a->admin_email)->toBe('boss@example.org')->and($a->listed)->toBeFalse()->and($a->notes)->toBe('hello')
        ->and(InstanceAuditLog::where('action', 'warehouse.updated')->where('warehouse_id', $a->id)->exists())->toBeTrue();
});

test('warehouse:setup-code issues, reports and revokes a code, only for a warehouse awaiting setup', function () {
    $w = ($this->warehouse)('Waiting', 'awaiting_setup');
    $active = ($this->warehouse)('Running');

    $this->artisan('warehouse:setup-code', ['warehouse' => $w->id, '--status' => true])->expectsOutputToContain('No setup code')->assertSuccessful();
    $this->artisan('warehouse:setup-code', ['warehouse' => $w->id])->expectsOutputToContain('Setup code:')->assertSuccessful();
    expect(SetupCode::usable()->where('warehouse_id', $w->id)->count())->toBe(1);
    $this->artisan('warehouse:setup-code', ['warehouse' => $w->id, '--status' => true])->expectsOutputToContain('A code is waiting')->assertSuccessful();
    $this->artisan('warehouse:setup-code', ['warehouse' => $w->id, '--revoke' => true])->assertSuccessful();
    expect(SetupCode::usable()->where('warehouse_id', $w->id)->count())->toBe(0);

    $this->artisan('warehouse:setup-code', ['warehouse' => $active->id])->assertFailed();
});

test('warehouse:admin joins an existing login to a set-up warehouse as Administrator, directly, and asks for a name only when it has to', function () {
    $warehouse = Warehouse::default();
    $account = ($this->account)('rescue');

    $this->artisan('warehouse:admin', ['warehouse' => $warehouse->id, 'email' => $account->email])->assertFailed()->expectsOutputToContain('--first');
    expect(Membership::where('account_id', $account->id)->exists())->toBeFalse();

    $this->artisan('warehouse:admin', ['warehouse' => $warehouse->id, 'email' => $account->email, '--first' => 'Res', '--last' => 'Cue'])->assertSuccessful();

    $membership = Membership::where('account_id', $account->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect(DB::table('people_roles')->where('person_id', $membership->person_id)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
        ->and(DB::table('people')->where('id', $membership->person_id)->value('account_id'))->toBe($account->id)
        ->and(InstanceAuditLog::where('action', 'warehouse.admin_added')->where('details->email', $account->email)->exists())->toBeTrue();

    // Again: already a member, nothing new is needed.
    $this->artisan('warehouse:admin', ['warehouse' => $warehouse->id, 'email' => $account->email])->assertSuccessful();
    expect(Membership::where('account_id', $account->id)->count())->toBe(1);

    $waiting = ($this->warehouse)('NotYet', 'awaiting_setup');
    $this->artisan('warehouse:admin', ['warehouse' => $waiting->id, 'email' => $account->email])->assertFailed()->expectsOutputToContain('setup-code');
});

test('instance:maintenance puts the whole instance down and up again, and instance:status and warehouse:show describe what is there', function () {
    $this->artisan('instance:maintenance', ['action' => 'on', '--secret' => 'let-me-in'])->assertSuccessful();
    try {
        expect(app()->isDownForMaintenance())->toBeTrue();
        $this->artisan('instance:maintenance')->expectsOutputToContain('maintenance mode')->assertSuccessful();
        $this->artisan('instance:status')->expectsOutputToContain('ON')->assertSuccessful();
    } finally {
        $this->artisan('instance:maintenance', ['action' => 'off'])->assertSuccessful();
    }
    expect(app()->isDownForMaintenance())->toBeFalse()
        ->and(InstanceAuditLog::whereIn('action', ['instance.maintenance_on', 'instance.maintenance_off'])->count())->toBeGreaterThanOrEqual(2);
    $this->artisan('instance:maintenance', ['action' => 'sideways'])->assertFailed();

    $warehouse = Warehouse::default();
    $this->artisan('instance:status')->expectsOutputToContain($warehouse->display_name)->expectsOutputToContain('Instance admins')->assertSuccessful();
    $this->artisan('warehouse:show', ['warehouse' => $warehouse->id])->expectsOutputToContain('Administrators')->assertSuccessful();
    $this->artisan('instance:audit', ['--limit' => 5, '--action' => 'instance.maintenance'])->expectsOutputToContain('instance.maintenance_')->assertSuccessful();
});
