<?php

use App\Models\Person;
use App\Models\PersonCategory;

test('a person can be marked as an organization and have contacts linked to it', function () {
    $org = Person::create(['organization' => 'Macedonia SDA Church', 'is_organization' => true]);
    $contact = Person::create([
        'first_name' => 'Aaron',
        'last_name' => 'Swann',
        'parent_person_id' => $org->id,
        'contact_role' => 'Primary',
    ]);

    expect($contact->parent->id)->toBe($org->id)
        ->and($org->children->pluck('id')->all())->toBe([$contact->id]);
});

test('creating a person with a category tag links it', function () {
    $category = PersonCategory::create(['name' => 'Supplier']);
    $person = Person::create(['organization' => 'ACME Supplies', 'category_id' => $category->id]);

    expect($person->category->name)->toBe('Supplier');
});

test('the People API rejects a person being set as their own parent organization', function () {
    $user = userWithPermissions('manage-people');
    $org = Person::create(['organization' => 'Some Org', 'is_organization' => true]);

    $this->actingAs($user)
        ->putJson("/json/people/{$org->id}", [
            'organization' => 'Some Org',
            'parent_person_id' => $org->id,
        ])
        ->assertStatus(422);
});

test('the People index can be filtered to organizations only, for the parent-org picker', function () {
    $user = userWithPermissions('manage-people');
    Person::create(['organization' => 'An Org', 'is_organization' => true]);
    Person::create(['first_name' => 'Not', 'last_name' => 'AnOrg', 'is_organization' => false]);

    $response = $this->actingAs($user)->getJson('/json/people?is_organization=1')->assertOk();

    expect($response->json('records'))->toHaveCount(1)
        ->and($response->json('records.0.organization'))->toBe('An Org');
});

test('person-categories can be created inline (quick-add) under manage-people', function () {
    $user = userWithPermissions('manage-people');

    $this->actingAs($user)
        ->postJson('/json/person-categories', ['name' => 'Warehouse Contact'])
        ->assertCreated()
        ->assertJsonPath('record.name', 'Warehouse Contact');

    expect(PersonCategory::where('name', 'Warehouse Contact')->exists())->toBeTrue();
});
