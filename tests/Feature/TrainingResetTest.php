<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Training Mode's reset: a reset point is taken when the mode is turned on, and "Turn off and reset" saves a class
// archive, then restores it. These run a real backup and restore against the test database.

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Models\Person;
use App\Models\SetupState;
use App\Models\TrainingRegistration;
use App\Models\TrainingSetting;
use App\Models\Transaction;
use App\Services\TrainingReset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\SchemaSnapshot;

/** TrainingReset without the detached process: records what it would have launched. */
class FakeLaunchTrainingReset extends TrainingReset
{
    public array $launched = [];

    protected function launch(string $file, string $stamp): void
    {
        $this->launched[] = [$file, $stamp];
    }
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/ri-training-reset-'.bin2hex(random_bytes(4));
    mkdir($this->tmp.'/backups', 0777, true);
    mkdir($this->tmp.'/private', 0777, true);
    config(['backup.dir' => $this->tmp.'/backups', 'filesystems.disks.local.root' => $this->tmp.'/private']);
    Storage::forgetDisk('local');
    $this->reset = new FakeLaunchTrainingReset;
    app()->instance(TrainingReset::class, $this->reset);
    SetupState::current()->update(['completed_at' => now()]);
});

afterEach(function () {
    @unlink(storage_path('framework/down'));
    if (! empty($GLOBALS['training_reset_dirtied_db'])) {
        Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions());
        $GLOBALS['training_reset_dirtied_db'] = false;
    }
    Storage::forgetDisk('local');
    exec('rm -rf '.escapeshellarg($this->tmp));
});

test('turning Training Mode on takes a reset point first; without one it cannot be reset', function () {
    $this->actingAs(userWithPermissions('admin-system', 'manage-training'));

    $this->putJson('/json/training/mode', ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);
    $point = $this->reset->resetPointPath();
    expect($point)->not->toBeNull()->and(is_file($point))->toBeTrue()
        ->and($this->getJson('/json/training/status')->json('reset_point.bytes'))->toBeGreaterThan(0);

    // Off and on again without a reset point: the old one is not reused.
    $this->putJson('/json/training/mode', ['enabled' => false])->assertOk();
    TrainingSetting::current()->update(['reset_point_file' => null]);
    $this->putJson('/json/training/mode', ['enabled' => true, 'reset_point' => false])->assertOk();
    expect($this->reset->resetPointPath())->toBeNull();
    $this->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertStatus(422);
});

test('the reset needs admin-system and the typed confirmation', function () {
    $this->actingAs(userWithPermissions('admin-system', 'manage-training'));
    $this->putJson('/json/training/mode', ['enabled' => true])->assertOk();

    $this->postJson('/json/training/reset', ['confirm' => 'reset please'])->assertStatus(422);
    $this->actingAs(userWithPermissions('manage-training'));
    $this->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertForbidden();
    expect($this->reset->launched)->toBe([]);
});

test('reset saves a class archive, then puts the database back to the reset point', function () {
    // A real dump only sees committed rows, so leave the test's wrapping transaction (as RestoreTest does).
    $GLOBALS['training_reset_dirtied_db'] = true;
    Artisan::call('migrate:fresh', SchemaSnapshot::freshOptions());
    SetupState::current()->update(['completed_at' => now()]);
    $admin = userWithPermissions('admin-system', 'manage-training');
    $this->actingAs($admin);
    $this->putJson('/json/training/mode', ['enabled' => true])->assertOk();
    $point = $this->reset->resetPointPath();

    // The class: a trainee who signed up, and some practice work.
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 2);
    TrainingRegistration::create(['person_id' => $trainee->id, 'email' => 'ann@example.com', 'wants_updates' => true]);
    Transaction::create(['type' => 'donation', 'category' => 'donation', 'order_date' => now()->toDateString(),
        'status_id' => Transaction::statusId(Transaction::STATUS_RECEIVED)]);

    $stamp = $this->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertStatus(202)->json('archive');
    expect($this->reset->launched)->toBe([[basename($point), $stamp]])
        ->and($this->reset->status()['state'])->toBe('running')
        ->and(Storage::disk('local')->get("training-archives/$stamp/signups.csv"))->toContain('ann@example.com');
    $this->postJson('/json/training/reset', ['confirm' => 'RESET'])->assertStatus(422); // one at a time

    // The detached half.
    $this->reset->run(basename($point), $stamp);

    expect($this->reset->status()['state'])->toBe('complete')
        ->and(Person::find($trainee->id))->toBeNull()
        ->and(TrainingRegistration::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and(Person::find($admin->id))->not->toBeNull()
        ->and(TrainingSetting::current()->enabled)->toBeFalse()
        ->and(is_file($point))->toBeFalse()
        ->and(app()->isDownForMaintenance())->toBeFalse()
        // The archive is files, so it outlives the restore, and the page lists it.
        ->and(app(TrainingReset::class)->archives()[0]['stamp'])->toBe($stamp);

    $this->actingAs($admin);
    $this->get("/report/training/archives/$stamp/signups.csv")->assertOk();
    $this->get("/report/training/archives/$stamp/..%2F..%2F.env")->assertNotFound();
    $this->get('/report/training/archives/../signups.csv')->assertNotFound();
});
