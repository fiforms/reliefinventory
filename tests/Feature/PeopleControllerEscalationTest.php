<?php

use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;

test('you cannot assign a role that grants a permission you do not hold yourself', function () {
    $powerfulRole = Role::create(['name' => 'Powerful Role']);
    $permission = Permission::create(['key' => 'powerful-permission', 'name' => 'Powerful']);
    $powerfulRole->permissions()->attach($permission->id);

    $actor = userWithPermissions('manage-people'); // no "powerful-permission"

    $response = $this->actingAs($actor)->postJson('/json/people', [
        'first_name' => 'New', 'last_name' => 'Person',
        'people_roles' => [['role_id' => $powerfulRole->id]],
    ]);

    $response->assertStatus(403);
    expect(Person::where('first_name', 'New')->exists())->toBeFalse();
});

test('you can assign a role that only grants permissions you already hold', function () {
    $role = Role::create(['name' => 'Modest Role']);
    $permission = Permission::create(['key' => 'modest-permission', 'name' => 'Modest']);
    $role->permissions()->attach($permission->id);

    $actor = userWithPermissions('manage-people', 'modest-permission');

    $response = $this->actingAs($actor)->postJson('/json/people', [
        'first_name' => 'New', 'last_name' => 'Person',
        'people_roles' => [['role_id' => $role->id]],
    ]);

    $response->assertCreated();
});

test('you cannot grant a per-person permission override you do not hold yourself', function () {
    $permission = Permission::create(['key' => 'special-permission', 'name' => 'Special']);
    $actor = userWithPermissions('manage-people');
    $target = Person::create(['first_name' => 'Target', 'last_name' => 'Person']);

    $response = $this->actingAs($actor)->putJson('/json/people/'.$target->id, [
        'first_name' => 'Target', 'last_name' => 'Person',
        'person_permissions' => [['permission_id' => $permission->id, 'granted' => true]],
    ]);

    $response->assertStatus(403);
});

test('you cannot modify a person who already holds a permission you lack, even for an unrelated field', function () {
    $role = Role::create(['name' => 'Elevated Role']);
    $permission = Permission::create(['key' => 'elevated-permission', 'name' => 'Elevated']);
    $role->permissions()->attach($permission->id);

    $target = Person::create(['first_name' => 'Elevated', 'last_name' => 'Person']);
    $target->roles()->attach($role->id);

    $actor = userWithPermissions('manage-people'); // lacks elevated-permission

    $response = $this->actingAs($actor)->putJson('/json/people/'.$target->id, [
        'first_name' => 'Elevated', 'last_name' => 'Person', 'phone' => '555-1234',
    ]);

    $response->assertStatus(403);
    expect($target->fresh()->phone)->not->toBe('555-1234');
});

test('even revoking a permission is blocked if the target holds something the actor lacks', function () {
    // Deliberately conservative: touching a person's record at all
    // requires the actor to match their full current permission set, not
    // just the one field being changed — otherwise a revoke could be used
    // to sneak in unrelated changes to someone you shouldn't be able to
    // touch. Mirrors the original bitwise check's blanket "you cannot
    // modify a person with a higher privilege level than your own".
    $role = Role::create(['name' => 'Base Role']);
    $permission = Permission::create(['key' => 'revoke-target-permission', 'name' => 'Revoke Target']);
    $role->permissions()->attach($permission->id);

    $target = Person::create(['first_name' => 'Base', 'last_name' => 'Person']);
    $target->roles()->attach($role->id);

    $actor = userWithPermissions('manage-people'); // lacks revoke-target-permission

    $response = $this->actingAs($actor)->putJson('/json/people/'.$target->id, [
        'first_name' => 'Base', 'last_name' => 'Person',
        'person_permissions' => [['permission_id' => $permission->id, 'granted' => false]],
    ]);

    $response->assertStatus(403);
});

test('revoking is allowed once the actor holds every permission the target currently has', function () {
    $role = Role::create(['name' => 'Base Role 2']);
    $permission = Permission::create(['key' => 'revoke-target-permission-2', 'name' => 'Revoke Target 2']);
    $role->permissions()->attach($permission->id);

    $target = Person::create(['first_name' => 'Base', 'last_name' => 'Person']);
    $target->roles()->attach($role->id);

    $actor = userWithPermissions('manage-people', 'revoke-target-permission-2');

    $response = $this->actingAs($actor)->putJson('/json/people/'.$target->id, [
        'first_name' => 'Base', 'last_name' => 'Person',
        'person_permissions' => [['permission_id' => $permission->id, 'granted' => false]],
    ]);

    $response->assertOk();
});

test('the people list flags can_edit false for a record the actor could not save, mirroring assertNoEscalation', function () {
    $role = Role::create(['name' => 'Flagged Role']);
    $permission = Permission::create(['key' => 'flagged-permission', 'name' => 'Flagged']);
    $role->permissions()->attach($permission->id);

    $untouchable = Person::create(['first_name' => 'Untouchable', 'last_name' => 'Person']);
    $untouchable->roles()->attach($role->id);
    $touchable = Person::create(['first_name' => 'Touchable', 'last_name' => 'Person']);

    $actor = userWithPermissions('manage-people'); // lacks flagged-permission

    $records = $this->actingAs($actor)->getJson('/json/people')->assertOk()->json('records');
    $records = collect($records)->keyBy('id');

    expect($records[$untouchable->id]['can_edit'])->toBeFalse()
        ->and($records[$touchable->id]['can_edit'])->toBeTrue();
});
