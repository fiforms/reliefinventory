<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\County;
use App\Models\Person;

test('a person can be created with a county', function () {
    $county = County::create(['county' => 'Thurston', 'state' => 'WA']);
    $actor = userWithPermissions('manage-people');

    $this->actingAs($actor)->postJson('/json/people', [
        'first_name' => 'New', 'last_name' => 'Person', 'county_id' => $county->id,
    ])->assertCreated();

    expect(Person::where('first_name', 'New')->first()->county_id)->toBe($county->id);
});

test('a person county can be updated', function () {
    $county = County::create(['county' => 'Pierce', 'state' => 'WA']);
    $person = Person::create(['first_name' => 'Existing', 'last_name' => 'Person']);
    $actor = userWithPermissions('manage-people');

    $this->actingAs($actor)->putJson('/json/people/'.$person->id, [
        'first_name' => 'Existing', 'last_name' => 'Person', 'county_id' => $county->id,
    ])->assertOk();

    expect($person->fresh()->county_id)->toBe($county->id);
});
