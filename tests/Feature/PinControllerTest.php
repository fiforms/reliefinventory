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
