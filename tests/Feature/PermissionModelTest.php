<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

test('a role grants its default permissions to a person holding it', function () {
    $role = Role::create(['name' => 'Test Role']);
    $permission = Permission::create(['key' => 'test-permission', 'name' => 'Test']);
    $role->permissions()->attach($permission->id);

    $user = User::factory()->create();
    $user->roles()->attach($role->id);

    expect($user->hasPermission('test-permission'))->toBeTrue();
});

test('a person permission override of granted=true adds a capability beyond their roles', function () {
    $permission = Permission::create(['key' => 'extra-permission', 'name' => 'Extra']);
    $user = User::factory()->create(); // no roles at all

    expect($user->hasPermission('extra-permission'))->toBeFalse();

    $user->person_permissions()->attach($permission->id, ['granted' => true]);

    expect($user->fresh()->hasPermission('extra-permission'))->toBeTrue();
});

test('a person permission override of granted=false revokes a permission the role would otherwise grant', function () {
    $role = Role::create(['name' => 'Test Role 2']);
    $permission = Permission::create(['key' => 'revocable-permission', 'name' => 'Revocable']);
    $role->permissions()->attach($permission->id);

    $user = User::factory()->create();
    $user->roles()->attach($role->id);

    expect($user->hasPermission('revocable-permission'))->toBeTrue();

    $user->person_permissions()->attach($permission->id, ['granted' => false]);

    expect($user->fresh()->hasPermission('revocable-permission'))->toBeFalse();
});

test('an unknown permission key is denied, not granted', function () {
    $user = User::factory()->create();

    expect($user->hasPermission('nonexistent-key'))->toBeFalse();
});

test('holding multiple roles unions their permission grants', function () {
    $roleA = Role::create(['name' => 'Role A']);
    $roleB = Role::create(['name' => 'Role B']);
    $permA = Permission::create(['key' => 'perm-a', 'name' => 'A']);
    $permB = Permission::create(['key' => 'perm-b', 'name' => 'B']);
    $roleA->permissions()->attach($permA->id);
    $roleB->permissions()->attach($permB->id);

    $user = User::factory()->create();
    $user->roles()->attach([$roleA->id, $roleB->id]);

    expect($user->hasPermission('perm-a'))->toBeTrue()
        ->and($user->hasPermission('perm-b'))->toBeTrue();
});
