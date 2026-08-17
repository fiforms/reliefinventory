<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\User;

test('a logged-in person can set their own PIN with their current password', function () {
    $user = User::factory()->create(); // factory password is 'password'

    $response = $this->actingAs($user)->put('/pin', [
        'current_password' => 'password',
        'pin' => '13579',
        'pin_confirmation' => '13579',
    ]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->hasPin())->toBeTrue()
        ->and($user->fresh()->verifyPin('13579'))->toBeTrue();
});

test('setting a PIN fails with the wrong current password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put('/pin', [
        'current_password' => 'wrong-password',
        'pin' => '13579',
        'pin_confirmation' => '13579',
    ]);

    $response->assertSessionHasErrors('current_password');
    expect($user->fresh()->hasPin())->toBeFalse();
});

test('setting a PIN requires exactly 5 digits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/pin', [
        'current_password' => 'password',
        'pin' => '123',
        'pin_confirmation' => '123',
    ])->assertSessionHasErrors('pin');
});

test('setting a PIN rejects more than 2 repeated digits in a row', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/pin', [
        'current_password' => 'password',
        'pin' => '11123',
        'pin_confirmation' => '11123',
    ])->assertSessionHasErrors('pin');
});

test('setting a PIN rejects more than 3 sequential digits ascending or descending', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/pin', [
        'current_password' => 'password',
        'pin' => '12345',
        'pin_confirmation' => '12345',
    ])->assertSessionHasErrors('pin');

    $this->actingAs($user)->put('/pin', [
        'current_password' => 'password',
        'pin' => '54321',
        'pin_confirmation' => '54321',
    ])->assertSessionHasErrors('pin');
});

test('setting a PIN allows short runs of repeats or sequences', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put('/pin', [
        'current_password' => 'password',
        'pin' => '11224',
        'pin_confirmation' => '11224',
    ]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->hasPin())->toBeTrue();
});

test('a PIN can be removed with the current password', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->put('/pin', [
        'current_password' => 'password', 'pin' => '13579', 'pin_confirmation' => '13579',
    ]);
    expect($user->fresh()->hasPin())->toBeTrue();

    $this->actingAs($user)->delete('/pin', ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasPin())->toBeFalse();
});
