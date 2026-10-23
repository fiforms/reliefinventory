<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Training Mode's tap-your-name login: only while training mode is on and only on an approved device, capped
// to TrainingMode::TRAINING_KEYS, and anything else needs a full login.

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Models\LoginHistory;
use App\Models\Permission;
use App\Models\Person;
use App\Models\TrainingSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TrainingMode;

function trainingOn(bool $on = true): void
{
    TrainingSetting::current()->update(['enabled' => $on]);
}

function trainingDevice(string $status = 'approved'): TrustedDevice
{
    return TrustedDevice::create([
        'device_token' => 'training-device-'.uniqid(),
        'status' => $status,
        'requested_at' => now(),
        'approved_at' => $status === 'approved' ? now() : null,
    ]);
}

function onDevice(TrustedDevice $device)
{
    return test()->withCredentials()->withCookie('pin_device_token', $device->device_token);
}

test('the login page goes to the training login only with training mode on and an approved device', function () {
    $device = trainingDevice();
    onDevice($device)->get('/login')->assertInertia(fn ($page) => $page->component('Auth/Login'));

    trainingOn();
    onDevice($device)->get('/login')->assertRedirect(route('training-login'));
    onDevice($device)->get('/login?email=1')->assertInertia(fn ($page) => $page->component('Auth/Login'));
    onDevice(trainingDevice('pending'))->get('/login')->assertInertia(fn ($page) => $page->component('Auth/Login'));
    $this->get('/training-login')->assertRedirect(route('login', ['email' => 1]));
});

test('the training login lists only active trainees', function () {
    trainingOn();
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 2);
    TrainingLoginController::createTrainee('Old', 'Gone', 1)->forceFill(['disabled_at' => now()])->save();
    User::factory()->create(['first_name' => 'Staff']);

    onDevice(trainingDevice())->get('/training-login')->assertInertia(fn ($page) => $page
        ->component('Auth/TrainingLogin')
        ->has('trainees', 1)
        ->where('trainees.0.id', $trainee->id)
        ->where('trainees.0.team', 2));
});

test('tapping a name logs a trainee in with no password and records the team', function () {
    trainingOn();
    $device = trainingDevice();
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', null);

    onDevice($device)->postJson('/training-login', ['person_id' => $trainee->id, 'team' => 3])->assertOk();

    $this->assertAuthenticatedAs(User::find($trainee->id));
    expect($trainee->fresh()->training_team)->toBe(3)
        ->and(LoginHistory::where('person_id', $trainee->id)->value('method'))->toBe('training');
});

test('only trainees can be logged in by name, only on an approved device with training on', function () {
    $device = trainingDevice();
    $trainee = TrainingLoginController::createTrainee('Ann', 'Able', 1);
    $staff = User::factory()->create();

    onDevice($device)->postJson('/training-login', ['person_id' => $trainee->id, 'team' => 1])->assertForbidden();
    trainingOn();
    onDevice(trainingDevice('pending'))->postJson('/training-login', ['person_id' => $trainee->id, 'team' => 1])->assertForbidden();
    onDevice($device)->postJson('/training-login', ['person_id' => $staff->id, 'team' => 1])->assertNotFound();
    onDevice($device)->postJson('/training-login', ['person_id' => $trainee->id, 'team' => 9])->assertUnprocessable();
    $this->assertGuest();
});

test('Add New creates a trainee with the Trainee role and logs them in', function () {
    trainingOn();
    $device = trainingDevice();

    onDevice($device)->postJson('/training-login/trainees', ['first_name' => 'Bo', 'last_name' => 'Baker', 'team' => 4])
        ->assertCreated();

    $person = Person::where('first_name', 'Bo')->firstOrFail();
    expect($person->is_trainee)->toBeTrue()
        ->and($person->training_team)->toBe(4)
        ->and($person->email)->toEndWith('@training.invalid')
        ->and($person->password)->toBeNull()
        ->and($person->roles()->pluck('name')->all())->toBe([TrainingMode::ROLE]);
    $this->assertAuthenticatedAs(User::find($person->id));

    // The same name again is refused, not duplicated.
    auth()->logout();
    onDevice($device)->postJson('/training-login/trainees', ['first_name' => 'Bo', 'last_name' => 'Baker', 'team' => 4])
        ->assertStatus(409);
    expect(Person::where('first_name', 'Bo')->count())->toBe(1);
});

test('a training login is capped to station work even for an administrator, and asks for a full login', function () {
    trainingOn();
    $device = trainingDevice();
    $admin = TrainingLoginController::createTrainee('Ada', 'Admin', 1);
    foreach (['admin-system', 'manage-users', 'adjust-inventory', 'manage-receiving'] as $key) {
        $admin->person_permissions()->attach(Permission::firstOrCreate(['key' => $key], ['name' => $key])->id, ['granted' => true]);
    }

    onDevice($device)->postJson('/training-login', ['person_id' => $admin->id, 'team' => 1])->assertOk();

    $user = User::find($admin->id);
    expect($user->hasPermission('manage-receiving'))->toBeTrue()
        ->and($user->hasPermission('admin-system'))->toBeFalse()
        ->and($user->hasPermission('adjust-inventory'))->toBeFalse();

    $this->get('/setup/users')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Auth/TrainingFullLogin')->where('path', '/setup/users')->where('training.session', true));
    $this->getJson('/json/users')->assertForbidden()->assertJsonFragment(['message' => 'This needs a full login — a training login can only do station work.']);
    $this->get('/receiving')->assertOk()->assertInertia(fn ($page) => $page->component('Receiving'));

    // Leaving for a full login ends the training session.
    $this->post('/training-login/exit', ['to' => 'pin'])->assertRedirect(route('unlock'));
    $this->assertGuest();
});

test('a password login of the same person is not capped', function () {
    $user = User::factory()->create();
    $user->person_permissions()->attach(Permission::firstOrCreate(['key' => 'admin-system'], ['name' => 'admin-system'])->id, ['granted' => true]);

    $this->actingAs($user)->withSession([TrainingMode::SESSION_KEY => $user->id + 1]);
    expect($user->hasPermission('admin-system'))->toBeTrue();
});
