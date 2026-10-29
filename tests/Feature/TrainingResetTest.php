<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Resetting a training warehouse (MULTI_WAREHOUSE.md §12): save a class archive, then rebuild that one warehouse from its seed. The
// gates run against the shared test warehouse; the rebuild itself runs against a real provisioned training warehouse (it
// needs the provisioning test user of dev/TESTING.md and skips without it).

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Models\Person;
use App\Models\TrainingRegistration;
use App\Models\TrainingSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FirstRunService;
use App\Services\TrainingReset;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseCreator;
use App\Services\Warehouses\WarehouseSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\SchemaSnapshot;

/** TrainingReset without the detached process: records what it would have launched. */
class FakeLaunchTrainingReset extends TrainingReset
{
    public array $launched = [];

    protected function launch(string $stamp): void
    {
        $this->launched[] = $stamp;
    }
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-training-reset-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/backups', 0777, true);
    config(['backup.dir' => $this->tmp.'/backups']);
    $this->context = app(WarehouseContext::class);
});

afterEach(function () {
    $this->context->switchTo(Warehouse::default());
    Storage::forgetDisk('local');
    if (isset($this->warehouse)) {
        $this->admin->exec('DROP DATABASE IF EXISTS `'.$this->warehouse->schema_name.'`');
        $this->admin->exec("DROP USER IF EXISTS 'ri_".strtolower($this->warehouse->id)."'@'localhost'");
        File::deleteDirectory($this->warehouse->secretsPath());
        File::deleteDirectory(dirname($this->warehouse->storagePath()));
    }
    exec('rm -rf '.escapeshellarg($this->tmp));
});

test('the reset needs manage-training, the typed confirmation, and a training warehouse', function () {
    $reset = new FakeLaunchTrainingReset;
    app()->instance(TrainingReset::class, $reset);
    trainingWarehouse();
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 2);
    TrainingRegistration::create(['person_id' => $trainee->id, 'email' => 'ann@example.com', 'wants_updates' => true]);

    $this->actingAs(userWithPermissions('general-access'))->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertForbidden();
    $this->actingAs(userWithPermissions('manage-training'));
    $this->postJson('/json/training/reset', ['confirm' => 'reset please'])->assertStatus(422);
    expect($reset->launched)->toBe([]);

    // A real warehouse has no such tool at all, whoever asks.
    trainingWarehouse(false);
    $this->actingAs(userWithPermissions('manage-training'))->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertForbidden();
    expect($reset->launched)->toBe([]);
});

test('starting a reset saves the class archive, launches the detached half once, and refuses a second', function () {
    config(['filesystems.disks.local.root' => $this->tmp.'/private']);
    Storage::forgetDisk('local');
    $reset = new FakeLaunchTrainingReset;
    app()->instance(TrainingReset::class, $reset);
    trainingWarehouse();
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 2);
    TrainingRegistration::create(['person_id' => $trainee->id, 'email' => 'ann@example.com', 'wants_updates' => true]);
    $this->actingAs(userWithPermissions('manage-training'));

    $stamp = $this->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertStatus(202)->json('archive');

    expect($reset->launched)->toBe([$stamp])->and($reset->status()['state'])->toBe('running')
        ->and(Storage::disk('local')->get("training-archives/$stamp/signups.csv"))->toContain('ann@example.com')
        ->and($reset->dir())->toContain('/warehouses/'.Warehouse::current()->id.'/training');
    $this->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertStatus(422);

    $this->get("/report/training/archives/$stamp/signups.csv")->assertOk();
    $this->get("/report/training/archives/$stamp/..%2F..%2F.env")->assertNotFound();
});

test('a reset rebuilds that one warehouse from its seed and keeps its people, certificate details and archives', function () {
    $base = config('database.connections.'.config('warehouses.provisioning.base_connection'));
    try {
        $this->admin = new PDO("mysql:host={$base['host']};port={$base['port']}", 'ri_test_provisioner', 'ri_test_provisioner', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        $this->markTestSkipped('The provisioning test user (dev/TESTING.md) does not exist here.');
    }
    config(['warehouses.provisioning' => [
        'admin_user' => 'ri_test_provisioner', 'admin_password' => 'ri_test_provisioner', 'admin_password_file' => '/nonexistent',
        'schema_prefix' => 'test_reliefinventory_tr'.bin2hex(random_bytes(2)), 'user_host' => 'localhost', 'max_user_connections' => 7,
    ] + config('warehouses.provisioning')]);

    $creator = app(WarehouseCreator::class);
    $creator->migrateOptions = SchemaSnapshot::freshOptions();
    [$this->warehouse] = $creator->create('Class Room', null, 'test', training: true);
    $warehouse = $this->warehouse->fresh();
    expect($warehouse->is_training)->toBeTrue()->and($warehouse->status)->toBe('awaiting_setup');

    // The trainer sets it up with their login, like any new warehouse.
    $trainer = new Account(['email' => 'trainer@example.org']);
    $trainer->forceFill(['password' => Hash::make('trainers-password-1'), 'email_verified_at' => now()])->save();
    $code = SetupCode::where('warehouse_id', $warehouse->id)->firstOrFail();
    $this->context->run($warehouse, fn () => app(WarehouseSetup::class)->withExistingAccount($warehouse, $code, $trainer, ['first_name' => 'Tess', 'last_name' => 'Trainer']));
    $oldPersonId = Membership::where('account_id', $trainer->id)->where('warehouse_id', $warehouse->id)->value('person_id');

    $stamp = $this->context->run($warehouse, function () {
        // The class: a trainee, a sign-up, practice work, an upload, certificate details, and a trainer's signature.
        $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 2);
        TrainingRegistration::create(['person_id' => $trainee->id, 'email' => 'ann@example.com', 'wants_updates' => true]);
        Transaction::create(['type' => 'donation', 'category' => 'donation', 'order_date' => now()->toDateString(), 'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED)]);
        TrainingSetting::current()->update(['event_title' => 'Spring Class', 'trainer1_name' => 'Tess Trainer', 'trainer1_signature' => 'training-signatures/sig.png']);
        Storage::disk('local')->put('training-signatures/sig.png', 'png');
        Storage::disk('local')->put('receiving-photos/p.jpg', 'photo');

        $reset = app(TrainingReset::class);
        $stamp = $reset->archive();
        $reset->run($stamp);
        expect($reset->status()['state'])->toBe('complete', $reset->status()['error'] ?? '');

        return $stamp;
    });

    expect($warehouse->fresh()->status)->toBe('active');
    $this->context->run($warehouse, function () use ($warehouse, $trainer, $oldPersonId, $stamp) {
        $trainerPerson = User::where('account_id', $trainer->id)->firstOrFail();
        expect(Person::where('is_trainee', true)->count())->toBe(0)
            ->and(TrainingRegistration::count())->toBe(0)
            ->and(Transaction::count())->toBe(0)
            ->and(app(FirstRunService::class)->essentialDataSeeded())->toBeTrue()
            ->and(app(FirstRunService::class)->needsSetup())->toBeFalse()
            // The trainer is back on the same login, as Administrator, and the membership follows the new person row.
            ->and($trainerPerson->first_name)->toBe('Tess')
            ->and(DB::table('people_roles')->where('person_id', $trainerPerson->id)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
            ->and(Membership::where('account_id', $trainer->id)->where('warehouse_id', $warehouse->id)->value('person_id'))->toBe($trainerPerson->id)
            // Certificate details survive; the archive and the signature are kept; the class's uploads are not.
            ->and(TrainingSetting::current()->event_title)->toBe('Spring Class')
            ->and(Storage::disk('local')->exists('training-signatures/sig.png'))->toBeTrue()
            ->and(Storage::disk('local')->exists("training-archives/$stamp/signups.csv"))->toBeTrue()
            ->and(Storage::disk('local')->exists('receiving-photos/p.jpg'))->toBeFalse();
        expect($oldPersonId)->not->toBeNull();
    });
    // Nothing outside it changed: the login itself and the default warehouse are as they were.
    expect(Hash::check('trainers-password-1', $trainer->fresh()->password))->toBeTrue();
});

test('warehouse:training turns a training warehouse off and on, and refuses a real one', function () {
    $other = Warehouse::create(['schema_name' => 'test_reliefinventory_tw1', 'display_name' => 'Class Two', 'is_training' => true]);

    $this->artisan('warehouse:training', ['action' => 'disable', 'warehouse' => $other->id])->assertSuccessful();
    expect($other->fresh()->status)->toBe('suspended')
        ->and(Warehouse::findReachable($other->id))->toBeNull();
    $this->artisan('warehouse:training', ['action' => 'disable', 'warehouse' => $other->id])->assertFailed();
    $this->artisan('warehouse:training', ['action' => 'enable', 'warehouse' => $other->id])->assertSuccessful();
    expect($other->fresh()->status)->toBe('active');

    $this->artisan('warehouse:training', ['action' => 'disable', 'warehouse' => Warehouse::default()->id])->assertFailed();
    expect(Warehouse::default()->status)->toBe('active');
});
