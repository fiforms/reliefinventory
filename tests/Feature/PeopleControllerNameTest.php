<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\Person;

test('a person can be created with only an organization, no name at all', function () {
    $actor = userWithPermissions('manage-people');

    $this->actingAs($actor)->postJson('/json/people', [
        'organization' => 'Walmart',
    ])->assertCreated();

    $person = Person::where('organization', 'Walmart')->first();
    expect($person)->not->toBeNull()
        ->and($person->first_name)->toBeNull()
        ->and($person->last_name)->toBeNull()
        ->and($person->full_name)->toBe('Walmart');
});

test('a person cannot be created with neither a name nor an organization', function () {
    $actor = userWithPermissions('manage-people');

    $response = $this->actingAs($actor)->postJson('/json/people', [
        'phone' => '555-0100',
    ]);

    $response->assertStatus(422);
    expect(Person::where('phone', '555-0100')->exists())->toBeFalse();
});

test('a person can still be created with a full name and no organization, as before', function () {
    $actor = userWithPermissions('manage-people');

    $this->actingAs($actor)->postJson('/json/people', [
        'first_name' => 'Jane', 'last_name' => 'Doe',
    ])->assertCreated();

    expect(Person::where('first_name', 'Jane')->where('last_name', 'Doe')->exists())->toBeTrue();
});
