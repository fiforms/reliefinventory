<?php

use App\Mail\AccountCreated;
use App\Models\LoginHistory;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
    Notification::assertSentTo(User::find($person->id), ResetPassword::class);
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

test('creating a user with a password sets it, skips the invite and emails login details without the password', function () {
    Notification::fake();
    Mail::fake();

    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users', [
        'first_name' => 'Temp', 'last_name' => 'Password', 'email' => 'temp@example.com',
        'password' => 'TempPass-1234', 'require_password_change' => true, 'send_login_email' => true,
    ])->assertCreated();

    $person = Person::where('email', 'temp@example.com')->first();
    expect(Hash::check('TempPass-1234', $person->password))->toBeTrue();
    expect($person->password_change_required)->toBeTrue();
    expect($person->email_verified_at)->not->toBeNull();
    expect($person->approved_at)->not->toBeNull();

    Notification::assertNothingSent();
    Mail::assertSent(AccountCreated::class, function ($mail) {
        $html = $mail->render();

        return $mail->hasTo('temp@example.com')
            && str_contains($html, 'temp@example.com')
            && ! str_contains($html, 'TempPass-1234');
    });
});

test('creating a user with a password and no email option sends nothing', function () {
    Notification::fake();
    Mail::fake();

    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users', [
        'first_name' => 'Quiet', 'last_name' => 'User', 'email' => 'quiet@example.com',
        'password' => 'TempPass-1234', 'require_password_change' => false, 'send_login_email' => false,
    ])->assertCreated();

    expect(Person::where('email', 'quiet@example.com')->first()->password_change_required)->toBeFalse();
    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

test('a too-short admin-set password is rejected', function () {
    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users', [
        'first_name' => 'Short', 'last_name' => 'Pw', 'email' => 'short@example.com', 'password' => 'abc',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
    expect(Person::where('email', 'short@example.com')->exists())->toBeFalse();
});

test('an account flagged for a password change is held on the change-password screen until it picks a new one', function () {
    $user = userWithPermissions('manage-users');
    $user->forceFill(['password' => Hash::make('TempPass-1234'), 'password_change_required' => true])->save();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.change-required'));
    $this->actingAs($user)->getJson('/json/users')->assertStatus(403);
    $this->actingAs($user)->get(route('password.change-required'))->assertOk();

    // Re-using the temporary password doesn't count.
    $this->actingAs($user)->post(route('password.change-required.store'), [
        'password' => 'TempPass-1234', 'password_confirmation' => 'TempPass-1234',
    ])->assertSessionHasErrors('password');
    expect($user->fresh()->password_change_required)->toBeTrue();

    $this->actingAs($user)->post(route('password.change-required.store'), [
        'password' => 'MyOwnPass-5678', 'password_confirmation' => 'MyOwnPass-5678',
    ])->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->password_change_required)->toBeFalse();
    expect(Hash::check('MyOwnPass-5678', $fresh->password))->toBeTrue();
    $this->actingAs($fresh)->getJson('/json/users')->assertOk();
});

test('the user list reports the password-change flag', function () {
    $actor = userWithPermissions('manage-users');
    $target = User::factory()->create();
    $target->forceFill(['password_change_required' => true])->save();

    $record = collect($this->actingAs($actor)->getJson('/json/users')->json('records'))->firstWhere('id', $target->id);
    expect($record['password_change_required'])->toBeTrue();
});

test('creating a user records who created and approved it', function () {
    Notification::fake();
    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users', [
        'first_name' => 'Audit', 'last_name' => 'Trail', 'email' => 'audit@example.com',
    ])->assertCreated();

    $person = Person::where('email', 'audit@example.com')->first();
    expect($person->created_by_person_id)->toBe($actor->id);
    expect($person->approved_by_person_id)->toBe($actor->id);

    $record = collect($this->actingAs($actor)->getJson('/json/users')->json('records'))->firstWhere('id', $person->id);
    expect($record['created_by_name'])->toBe($actor->first_name.' '.$actor->last_name);
    expect($record['status'])->toBe('invited');
    expect($record['last_login_at'])->toBeNull();
});

test('the user list reports each account\'s last login', function () {
    $actor = userWithPermissions('manage-users');
    $target = User::factory()->create();
    LoginHistory::create(['person_id' => $target->id, 'method' => 'password', 'logged_in_at' => now()->subDays(3)]);
    LoginHistory::create(['person_id' => $target->id, 'method' => 'password', 'logged_in_at' => now()->subDay()]);

    $record = collect($this->actingAs($actor)->getJson('/json/users')->json('records'))->firstWhere('id', $target->id);
    expect(Carbon::parse($record['last_login_at'])->isSameDay(now()->subDay()))->toBeTrue();
    expect($record['status'])->toBe('active');
});

test('an admin can set another account\'s password and require a change', function () {
    Mail::fake();
    $actor = userWithPermissions('manage-users');
    $target = User::factory()->create();

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/set-password', [
        'password' => 'NewTemp-9876', 'require_password_change' => true, 'send_login_email' => true,
    ])->assertOk();

    $fresh = $target->fresh();
    expect(Hash::check('NewTemp-9876', $fresh->password))->toBeTrue();
    expect($fresh->password_change_required)->toBeTrue();
    Mail::assertSent(AccountCreated::class, fn ($mail) => ! str_contains($mail->render(), 'NewTemp-9876'));
});

test('setting a password is refused for your own account and for someone holding more than you', function () {
    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users/'.$actor->id.'/set-password', ['password' => 'NewTemp-9876'])
        ->assertStatus(422);

    $role = Role::create(['name' => 'Higher Role']);
    $permission = Permission::create(['key' => 'higher-only', 'name' => 'Higher']);
    $role->permissions()->attach($permission->id);
    $target = User::factory()->create();
    $target->roles()->attach($role->id);

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/set-password', ['password' => 'NewTemp-9876'])
        ->assertStatus(403);
    expect(Hash::check('NewTemp-9876', $target->fresh()->password))->toBeFalse();

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/require-password-change', ['required' => true])
        ->assertStatus(403);
    expect($target->fresh()->password_change_required)->toBeFalse();
});

test('requiring a password change toggles the flag, but not for an account with no password yet', function () {
    Notification::fake();
    $actor = userWithPermissions('manage-users');
    $target = User::factory()->create();

    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/require-password-change', ['required' => true])->assertOk();
    expect($target->fresh()->password_change_required)->toBeTrue();
    $this->actingAs($actor)->postJson('/json/users/'.$target->id.'/require-password-change', ['required' => false])->assertOk();
    expect($target->fresh()->password_change_required)->toBeFalse();

    $this->actingAs($actor)->postJson('/json/users', ['first_name' => 'No', 'last_name' => 'Pw', 'email' => 'nopw@example.com']);
    $invited = Person::where('email', 'nopw@example.com')->first();
    $this->actingAs($actor)->postJson('/json/users/'.$invited->id.'/require-password-change', ['required' => true])
        ->assertStatus(422);
});

test('bulk approve approves each account and records the approver', function () {
    $actor = userWithPermissions('manage-users');
    $pending = User::factory()->count(2)->create();
    $pending->each(fn ($u) => $u->forceFill(['approved_at' => null])->save());

    $response = $this->actingAs($actor)->postJson('/json/users/bulk', [
        'action' => 'approve', 'ids' => $pending->pluck('id')->all(),
    ])->assertOk();

    expect($response->json('done'))->toBe(2);
    $pending->each(function ($u) use ($actor) {
        $fresh = Person::find($u->id);
        expect($fresh->approved_at)->not->toBeNull();
        expect($fresh->approved_by_person_id)->toBe($actor->id);
    });
});

test('bulk deactivate skips your own account and reports why', function () {
    $actor = userWithPermissions('manage-users');
    $other = User::factory()->create();

    $response = $this->actingAs($actor)->postJson('/json/users/bulk', [
        'action' => 'deactivate', 'ids' => [$actor->id, $other->id],
    ])->assertOk();

    expect($response->json('done'))->toBe(1);
    expect($response->json('skipped.0.id'))->toBe($actor->id);
    expect($actor->fresh()->disabled_at)->toBeNull();
    expect($other->fresh()->disabled_at)->not->toBeNull();
});

test('bulk refuses an unknown action', function () {
    $actor = userWithPermissions('manage-users');

    $this->actingAs($actor)->postJson('/json/users/bulk', ['action' => 'delete', 'ids' => [1]])
        ->assertStatus(422);
});

test('the CSV export lists only the requested accounts', function () {
    $actor = userWithPermissions('manage-users');
    $wanted = User::factory()->create(['email' => 'wanted@example.com']);
    User::factory()->create(['email' => 'unwanted@example.com']);

    $response = $this->actingAs($actor)->get('/json/users/export.csv?ids='.$wanted->id)->assertOk();

    $csv = $response->getContent();
    expect($csv)->toContain('"First Name","Last Name",Email')
        ->toContain('wanted@example.com')
        ->toContain('Never')
        ->not->toContain('unwanted@example.com');
});
