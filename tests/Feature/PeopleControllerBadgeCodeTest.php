<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;

test('a badge code can be assigned to a person', function () {
    $user = userWithPermissions('manage-people');

    $this->actingAs($user)->postJson('/json/people', [
        'first_name' => 'Andrea', 'last_name' => 'Powalie', 'badge_code' => 'BADGE-100',
    ])->assertCreated();

    expect(Person::where('badge_code', 'BADGE-100')->exists())->toBeTrue();
});

test('badge codes must be unique across people', function () {
    Person::create(['first_name' => 'A', 'last_name' => 'One', 'badge_code' => 'DUP-1']);
    $user = userWithPermissions('manage-people');

    $this->actingAs($user)->postJson('/json/people', [
        'first_name' => 'B', 'last_name' => 'Two', 'badge_code' => 'DUP-1',
    ])->assertStatus(422);
});

test('a person can keep their own badge code unchanged on update', function () {
    $person = Person::create(['first_name' => 'A', 'last_name' => 'One', 'badge_code' => 'KEEP-1']);
    $user = userWithPermissions('manage-people');

    $this->actingAs($user)->putJson('/json/people/'.$person->id, [
        'first_name' => 'A', 'last_name' => 'One', 'badge_code' => 'KEEP-1',
    ])->assertOk();
});
