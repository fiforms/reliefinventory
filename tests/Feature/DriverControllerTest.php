<?php

use App\Models\Driver;
use App\Models\Person;

test('a driver can be quick-added with name, phone, and carrier', function () {
    $user = userWithPermissions('manage-receiving');

    $record = $this->actingAs($user)->postJson('/json/drivers', [
        'name' => 'Pat Driver',
        'phone' => '555-0100',
        'carrier' => 'ABC Trucking',
    ])->assertCreated()->json('record');

    expect(Driver::findOrFail($record['id']))
        ->name->toBe('Pat Driver')
        ->carrier->toBe('ABC Trucking');
});

test('the driver list is searchable/sortable by carrier since it comes back in the index', function () {
    $user = userWithPermissions('manage-receiving');
    Driver::create(['name' => 'Alex', 'carrier' => 'FastFreight']);
    Driver::create(['name' => 'Sam', 'carrier' => 'FastFreight']);

    $records = $this->actingAs($user)->getJson('/json/drivers')->assertOk()->json('records');

    expect(collect($records)->pluck('carrier')->unique()->all())->toBe(['FastFreight']);
});

test('a driver can be linked to a Person after the fact, for the "driver is also the donor" case', function () {
    $user = userWithPermissions('manage-receiving');
    $driver = Driver::create(['name' => 'Jamie']);
    $person = Person::create(['first_name' => 'Jamie', 'last_name' => 'Smith']);

    $this->actingAs($user)
        ->putJson('/json/drivers/'.$driver->id, ['name' => 'Jamie', 'person_id' => $person->id])
        ->assertOk();

    expect($driver->fresh()->person_id)->toBe($person->id);
});
