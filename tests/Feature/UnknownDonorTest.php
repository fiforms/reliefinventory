<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;

test('a canonical Unknown Donor person exists after migrating', function () {
    $unknown = Person::where('system_key', 'unknown-donor')->first();

    expect($unknown)->not->toBeNull()
        ->and($unknown->organization)->toBe('Unknown Donor')
        ->and($unknown->isSystem())->toBeTrue();
});

test('the Unknown Donor record is selectable from the normal people list like any donor', function () {
    $user = userWithPermissions('manage-people');

    $records = $this->actingAs($user)->getJson('/json/people')->assertOk()->json('records');

    expect(collect($records)->pluck('organization'))->toContain('Unknown Donor');
});

test('the Unknown Donor system record cannot be deleted', function () {
    $user = userWithPermissions('admin-people');
    $unknown = Person::where('system_key', 'unknown-donor')->firstOrFail();

    $this->actingAs($user)->deleteJson('/json/people/'.$unknown->id)->assertStatus(422);

    expect(Person::find($unknown->id))->not->toBeNull();
});

test('a normal person (not system-provided) can still be deleted', function () {
    $user = userWithPermissions('admin-people');
    $person = Person::create(['first_name' => 'Deletable', 'last_name' => 'Person']);

    $this->actingAs($user)->deleteJson('/json/people/'.$person->id)->assertOk();

    expect(Person::find($person->id))->toBeNull();
});
