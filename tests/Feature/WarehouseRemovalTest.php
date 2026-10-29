<?php

// MULTI_WAREHOUSE.md §6: archiving and removing a warehouse. The account rules and the guards run against plain rows; the removal
// itself runs against a real provisioned warehouse (needs the provisioning test user of dev/TESTING.md; skips without it).

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\InstanceAuditLog;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Models\Control\WarehouseDomain;
use App\Models\Person;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use App\Services\Warehouses\WarehouseDomains;
use App\Services\Warehouses\WarehouseRemoval;
use App\Services\Warehouses\WarehouseSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\SchemaSnapshot;
use Tests\SecondWarehouse;

beforeEach(function () {
    $this->removal = app(WarehouseRemoval::class);
    $this->context = app(WarehouseContext::class);
    $this->tmp = sys_get_temp_dir().'/ri-removal-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/backups', 0777, true);
    config(['backup.dir' => $this->tmp.'/backups']);
    $this->made = fn (string $name, array $attributes = []) => Warehouse::create(['schema_name' => 'test_reliefinventory_'.Str::random(6), 'display_name' => $name] + $attributes);
    $this->account = function (string $email, bool $instanceAdmin = false) {
        $account = new Account(['email' => $email]);
        $account->forceFill(['password' => Hash::make('a-long-passphrase-1'), 'email_verified_at' => now(), 'is_instance_admin' => $instanceAdmin])->save();

        return $account;
    };
});

afterEach(function () {
    $this->context->switchTo(Warehouse::default());
    exec('rm -rf '.escapeshellarg($this->tmp));
});

test('archiving stops a warehouse serving and remembers what it was; unarchiving puts it back', function () {
    $w = ($this->made)('Quiet Place', ['status' => 'suspended']);

    $this->removal->archive($w, 'tester');
    $w = $w->fresh();
    expect($w->status)->toBe('archived')->and($w->status_before_archive)->toBe('suspended')->and($w->archived_at)->not->toBeNull()
        ->and(Warehouse::findReachable($w->id))->toBeNull();
    expect(fn () => $this->removal->archive($w))->toThrow(RuntimeException::class, 'already archived');

    $this->removal->unarchive($w);
    expect($w->fresh()->status)->toBe('suspended')->and($w->fresh()->archived_at)->toBeNull();
    expect(fn () => $this->removal->unarchive($w->fresh()))->toThrow(RuntimeException::class, 'not archived');

    expect(InstanceAuditLog::where('warehouse_id', $w->id)->orderBy('id')->pluck('action')->all())->toBe(['warehouse.archived', 'warehouse.unarchived'])
        ->and(InstanceAuditLog::where('action', 'warehouse.archived')->value('actor'))->toBe('tester');
});

test('the default warehouse can be neither archived nor removed', function () {
    expect(fn () => $this->removal->archive(Warehouse::default()))->toThrow(RuntimeException::class, 'default warehouse')
        ->and(fn () => $this->removal->remove(Warehouse::default(), Warehouse::default()->display_name))->toThrow(RuntimeException::class, 'default warehouse');
});

test('removal refuses a warehouse that is not archived, and a name that was not typed, before touching anything', function () {
    $w = ($this->made)('Doomed Place');

    expect(fn () => $this->removal->remove($w, 'Doomed Place'))->toThrow(RuntimeException::class, 'Archive Doomed Place first');
    $this->removal->archive($w);
    expect(fn () => $this->removal->remove($w->fresh(), 'doomed place'))->toThrow(RuntimeException::class, 'name typed does not match')
        ->and(fn () => $this->removal->remove($w->fresh(), ''))->toThrow(RuntimeException::class, 'name typed does not match');
    expect(Warehouse::find($w->id))->not->toBeNull();

    $this->artisan('warehouse:remove', ['warehouse' => Warehouse::default()->id, '--confirm' => 'x'])->assertFailed();
    $this->artisan('warehouse:remove', ['warehouse' => $w->id, '--confirm' => 'wrong'])->assertFailed();
    expect(Warehouse::find($w->id))->not->toBeNull();
});

test('logins that belonged only to the warehouse go with it; instance admins and logins with a real home elsewhere stay', function () {
    $w = ($this->made)('Gone Place');
    $real = ($this->made)('Real Elsewhere');
    $training = SecondWarehouse::make($this, ['display_name' => 'Practice', 'is_training' => true])->fresh();

    $sole = ($this->account)('sole@example.org');
    $shared = ($this->account)('shared@example.org');
    $admin = ($this->account)('boss@example.org', true);
    $withTraining = ($this->account)('trainee-too@example.org');
    foreach ([[$sole, $w], [$shared, $w], [$shared, $real], [$admin, $w], [$withTraining, $w]] as $i => [$account, $warehouse]) {
        Membership::create(['account_id' => $account->id, 'warehouse_id' => $warehouse->id, 'person_id' => 100 + $i]);
    }
    // One login also belongs to a training warehouse, which does not count as a home.
    $trainingPerson = $this->context->run($training, fn () => Person::create(['first_name' => 'T', 'last_name' => 'Rainee', 'email' => 'trainee-too@example.org']));
    $this->context->run($training, fn () => DB::table('people')->where('id', $trainingPerson->id)->update(['account_id' => $withTraining->id]));
    Membership::create(['account_id' => $withTraining->id, 'warehouse_id' => $training->id, 'person_id' => $trainingPerson->id, 'is_training' => true]);

    // Offers: one made by the removed warehouse, one to a login that is going.
    MembershipInvitation::create(['warehouse_id' => $w->id, 'person_id' => 5, 'email' => 'someone@example.org', 'source' => 'admin', 'expires_at' => now()->addDay()]);
    MembershipInvitation::create(['warehouse_id' => $real->id, 'person_id' => 6, 'email' => 'sole@example.org', 'source' => 'admin', 'expires_at' => now()->addDay()]);
    MembershipInvitation::create(['warehouse_id' => $real->id, 'person_id' => 7, 'email' => 'shared@example.org', 'source' => 'admin', 'expires_at' => now()->addDay()]);

    $result = $this->removal->forgetAccounts($w);

    expect($result['removed'])->toEqualCanonicalizing(['sole@example.org', 'trainee-too@example.org'])->and($result['kept'])->toBe(2)
        ->and(Account::findByEmail('sole@example.org'))->toBeNull()
        ->and(Account::findByEmail('trainee-too@example.org'))->toBeNull()
        ->and(Account::findByEmail('shared@example.org'))->not->toBeNull()
        ->and(Account::findByEmail('boss@example.org'))->not->toBeNull()
        // The kept logins lost only this membership.
        ->and(Membership::where('account_id', $shared->id)->pluck('warehouse_id')->all())->toBe([$real->id])
        ->and(Membership::where('account_id', $admin->id)->count())->toBe(0)
        // The training warehouse's copy of a removed login's link is cleared, and nothing else.
        ->and(Membership::where('warehouse_id', $training->id)->count())->toBe(0)
        ->and($this->context->run($training, fn () => DB::table('people')->where('id', $trainingPerson->id)->value('account_id')))->toBeNull()
        // Offers from this warehouse and to a removed login are gone; another's offer to a kept login stays.
        ->and(MembershipInvitation::pluck('email')->all())->toBe(['shared@example.org']);
});

test('a real warehouse is removed completely: final backup, schema, user, files, key, logins and control rows', function () {
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $this->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $this->markTestSkipped('The provisioning test user (dev/TESTING.md) does not exist here.');
    }
    config(['warehouses.provisioning' => [
        'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
        'schema_prefix' => 'test_reliefinventory_rm'.bin2hex(random_bytes(2)), 'user_host' => 'localhost', 'max_user_connections' => 7,
    ] + config('warehouses.provisioning')]);

    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    [$warehouse] = $creator->create('Short Lived', null, 'test');
    $warehouse = $warehouse->fresh();
    $schema = $warehouse->schema_name;
    $user = 'ri_'.strtolower($warehouse->id);

    $holder = ($this->account)('holder@example.org');
    $code = SetupCode::where('warehouse_id', $warehouse->id)->firstOrFail();
    $this->context->run($warehouse, fn () => app(WarehouseSetup::class)->withExistingAccount($warehouse, $code, $holder, ['first_name' => 'Hol', 'last_name' => 'Der']));
    app(WarehouseDomains::class)->add($warehouse->fresh(), 'short-lived.example.org');
    $warehouse->getConnection()->table('jobs')->insert([
        ['queue' => 'default', 'payload' => json_encode(['warehouse' => $warehouse->id, 'job' => 'x']), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
        ['queue' => 'default', 'payload' => json_encode(['warehouse' => Warehouse::default()->id, 'job' => 'y']), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
    ]);
    $dirs = [dirname($warehouse->storagePath()), $warehouse->secretsPath()];
    expect(is_dir($dirs[0]))->toBeTrue()->and(is_dir($dirs[1]))->toBeTrue();

    $this->removal->archive($warehouse->fresh(), 'tester');
    $result = $this->removal->remove($warehouse->fresh(), 'Short Lived', 'tester');

    // The final backup is kept, and holds the warehouse's data.
    expect($result['backup'])->toStartWith($this->tmp.'/backups/removed-warehouses/'.$warehouse->id.'/')->and(is_file($result['backup']))->toBeTrue();
    $zip = new ZipArchive;
    $zip->open($result['backup']);
    expect($zip->locateName('database/db.sql'))->not->toBeFalse()->and($zip->locateName('database/accounts.json'))->not->toBeFalse();

    // Gone: the schema, its database user, its folders, its control rows and queued jobs; the login that was only here.
    expect($this->admin->query("SHOW DATABASES LIKE '{$schema}'")->fetchColumn())->toBeFalse();
    try {
        $this->admin->exec("CREATE USER '{$user}'@'localhost'");
        $this->admin->exec("DROP USER '{$user}'@'localhost'");
        $userGone = true;
    } catch (PDOException) {
        $userGone = false;
    }
    expect($userGone)->toBeTrue()
        ->and(is_dir($dirs[0]))->toBeFalse()->and(is_dir($dirs[1]))->toBeFalse()
        ->and(Warehouse::find($warehouse->id))->toBeNull()
        ->and(WarehouseDomain::where('warehouse_id', $warehouse->id)->count())->toBe(0)
        ->and(SetupCode::where('warehouse_id', $warehouse->id)->count())->toBe(0)
        ->and($warehouse->getConnection()->table('jobs')->where('payload', 'like', '%"warehouse":"'.$warehouse->id.'"%')->count())->toBe(0)
        ->and($warehouse->getConnection()->table('jobs')->where('payload', 'like', '%'.Warehouse::default()->id.'%')->count())->toBe(1)
        ->and($result['accounts_removed'])->toBe(['holder@example.org'])
        ->and(Account::findByEmail('holder@example.org'))->toBeNull();

    // And it is on the record, by the name it had.
    $entry = InstanceAuditLog::where('action', 'warehouse.removed')->where('warehouse_id', $warehouse->id)->firstOrFail();
    expect($entry->subject)->toBe('Short Lived')->and($entry->actor)->toBe('tester')
        ->and($entry->details['accounts_removed'])->toBe(['holder@example.org'])->and($entry->details['backup'])->toBe($result['backup'])
        ->and(InstanceAuditLog::where('warehouse_id', $warehouse->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['warehouse.created', 'domain.added', 'warehouse.archived', 'warehouse.removed']);
});
