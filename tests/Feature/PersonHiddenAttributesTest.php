<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Regression test for a real bug found 2026-08-16: Person had no $hidden at
// all, so PeopleController::index() (serializing full Person models) was
// leaking every bcrypt password hash to anyone holding manage-people — the
// whole volunteer tier by default. Found while checking whether the new
// pin_hash column would leak the same way; it would have.
test('the people list never serializes password or pin_hash', function () {
    $person = Person::create(['first_name' => 'Secret', 'last_name' => 'Holder']);
    $person->password = Hash::make('irrelevant');
    $person->pin_hash = Hash::make('13579');
    $person->save();

    $user = userWithPermissions('manage-people');
    $records = $this->actingAs($user)->getJson('/json/people')->assertOk()->json('records');

    $body = json_encode($records);
    expect($body)->not->toContain(Hash::make('irrelevant'))
        ->and(collect($records)->first())->not->toHaveKey('password')
        ->not->toHaveKey('pin_hash');
});

test('User (the Auth::user() model) also hides password and pin_hash from JSON', function () {
    $user = User::factory()->create();
    $user->pin_hash = Hash::make('24680');
    $user->save();

    $array = $user->fresh()->toArray();

    expect($array)->not->toHaveKey('password')->not->toHaveKey('pin_hash');
});
