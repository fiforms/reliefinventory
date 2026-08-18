<?php

use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('the user administration routes require the manage-users permission', function () {
    $actor = userWithPermissions('manage-people'); // not manage-users

    $this->actingAs($actor)->getJson('/json/users')->assertStatus(403);
});

test('creating a user sends a password-reset email and does not require a password', function () {
    Notification::fake();

    $role = Role::where('name', 'Office')->first() ?? Role::create(['name' => 'Office']);
    $actor = userWithPermissions('manage-users', ...$role->permissions()->pluck('key')->all());

    $response = $this->actingAs($actor)->postJson('/json/users', [
        'first_name' => 'New', 'last_name' => 'Staffer', 'email' => 'staffer@example.com',
        'people_roles' => [['role_id' => $role->id]],
    ]);

    $response->assertCreated();
    $person = Person::where('email', 'staffer@example.com')->first();
    expect($person)->not->toBeNull();
    expect($person->email_verified_at)->not->toBeNull();
    expect($person->password)->toBeNull();

    // Password::sendResetLink resolves the notifiable via the "users"
    // auth provider (App\Models\User) — same table/row as $person, but a
    // different Eloquent class, so assert against that class.
    Notification::assertSentTo(User::find($person->id), \Illuminate\Auth\Notifications\ResetPassword::class);
});

test('creating a user is blocked by the same escalation guard as PeopleController', function () {
    $powerfulRole = Role::create(['name' => 'Powerful Admin Role']);
    $permission = Permission::create(['key' => 'powerful-admin-permission', 'name' => 'Powerful']);
    $powerfulRole->permissions()->attach($permission->id);

    $actor = userWithPermissions('manage-users'); // no "powerful-admin-permission"

    $response = $this->actingAs($actor)->postJson('/json/users', [
        'first_name' => 'New', 'last_name' => 'Staffer', 'email' => 'blocked@example.com',
        'people_roles' => [['role_id' => $powerfulRole->id]],
    ]);

    $response->assertStatus(403);
    expect(Person::where('email', 'blocked@example.com')->exists())->toBeFalse();
});

test('deactivating blocks login and reactivating restores it', function () {
    $actor = userWithPermissions('manage-users');
    $target = User::factory()->create();

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/deactivate')->assertOk();
    expect($target->fresh()->disabled_at)->not->toBeNull();

    auth()->logout();
    $this->postJson('/login', [
        'email' => $target->email, 'password' => 'password',
    ]);
    $this->assertGuest();

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/reactivate')->assertOk();
    expect($target->fresh()->disabled_at)->toBeNull();
});

test('an admin cannot deactivate their own account', function () {
    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users/'.$actor->id.'/deactivate')
        ->assertStatus(422);
    expect($actor->fresh()->disabled_at)->toBeNull();
});

test('you cannot deactivate a person who holds a permission you lack', function () {
    $role = Role::create(['name' => 'Elevated Staff Role']);
    $permission = Permission::create(['key' => 'elevated-staff-permission', 'name' => 'Elevated']);
    $role->permissions()->attach($permission->id);

    $target = User::factory()->create();
    $target->roles()->attach($role->id);

    $actor = userWithPermissions('manage-users'); // lacks elevated-staff-permission

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/deactivate')
        ->assertStatus(403);
    expect($target->fresh()->disabled_at)->toBeNull();
});
