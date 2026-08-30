<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;

test('address_verified_at is never set from a raw client value', function () {
    $actor = userWithPermissions('manage-people');

    $response = $this->actingAs($actor)->postJson('/json/people', [
        'first_name' => 'New', 'last_name' => 'Person',
        'address' => '123 Main St', 'address_verified_at' => now()->toIso8601String(),
    ]);

    $response->assertCreated();
    expect(Person::where('first_name', 'New')->first()->address_verified_at)->toBeNull();
});

test('verified_address true stamps address_verified_at on create', function () {
    $actor = userWithPermissions('manage-people');

    $this->actingAs($actor)->postJson('/json/people', [
        'first_name' => 'New', 'last_name' => 'Person',
        'address' => '123 Main St', 'verified_address' => true,
    ])->assertCreated();

    expect(Person::where('first_name', 'New')->first()->address_verified_at)->not->toBeNull();
});

test('verified_address true stamps address_verified_at on update', function () {
    $person = Person::create(['first_name' => 'Existing', 'last_name' => 'Person', 'address' => '123 Main St']);
    $actor = userWithPermissions('manage-people');

    $this->actingAs($actor)->putJson('/json/people/'.$person->id, [
        'first_name' => 'Existing', 'last_name' => 'Person',
        'address' => '123 Main St', 'verified_address' => true,
    ])->assertOk();

    expect($person->fresh()->address_verified_at)->not->toBeNull();
});

test('changing the address clears a previously-verified flag', function () {
    $person = Person::create(['first_name' => 'Existing', 'last_name' => 'Person', 'address' => '123 Main St']);
    $person->address_verified_at = now();
    $person->save();

    $actor = userWithPermissions('manage-people');
    $this->actingAs($actor)->putJson('/json/people/'.$person->id, [
        'first_name' => 'Existing', 'last_name' => 'Person',
        'address' => '456 Other St', // changed, and no verified_address flag sent
    ])->assertOk();

    expect($person->fresh()->address_verified_at)->toBeNull();
});

test('saving without touching the address preserves a previously-verified flag', function () {
    $person = Person::create(['first_name' => 'Existing', 'last_name' => 'Person', 'address' => '123 Main St']);
    $person->address_verified_at = now();
    $person->save();
    $verifiedAt = $person->address_verified_at;

    $actor = userWithPermissions('manage-people');
    $this->actingAs($actor)->putJson('/json/people/'.$person->id, [
        'first_name' => 'Still Existing', 'last_name' => 'Person', // unrelated field changes
        'address' => '123 Main St', // unchanged
    ])->assertOk();

    expect($person->fresh()->address_verified_at->equalTo($verifiedAt))->toBeTrue();
});
