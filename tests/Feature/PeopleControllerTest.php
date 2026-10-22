<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Models\County;
use App\Models\Person;

// ---- names and organizations --------------------------------------------------------------------

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

// ---- county -------------------------------------------------------------------------------------

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

// ---- badge codes --------------------------------------------------------------------------------

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

// ---- address verification -----------------------------------------------------------------------

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
