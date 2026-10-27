<?php

use App\Auth\AccountSession;
use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Control\Warehouse;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// MULTI_WAREHOUSE.md phase 0b: logins live in the accounts schema; people are per warehouse.

/** Give $user's account a membership in another (real or training) warehouse. */
function joinOtherWarehouse(User $user, bool $training = false): Warehouse
{
    $other = Warehouse::create([
        'schema_name' => 'test_reliefinventory_other_'.uniqid(), 'display_name' => 'Other '.uniqid(),
        'is_training' => $training,
    ]);
    Membership::create(['account_id' => $user->account_id, 'warehouse_id' => $other->id, 'person_id' => 999, 'is_training' => $training]);

    return $other;
}

test('a new user gets an account and a membership here; credentials live only in the account', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $account = Account::findByEmail('PAT@example.com');

    expect($account)->not->toBeNull()
        ->and($user->account_id)->toBe($account->id)
        ->and(Hash::check('password', $account->password))->toBeTrue()
        ->and($account->email_verified_at)->not->toBeNull()
        ->and(Membership::where('account_id', $account->id)->where('warehouse_id', Warehouse::default()->id)->value('person_id'))->toBe($user->id)
        ->and(Schema::hasColumn('people', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('people', 'email_verified_at'))->toBeFalse();
});

test('donors with only a contact email are not users and get no account', function () {
    $donor = Person::create(['last_name' => 'Donor', 'email' => 'donor@example.com']);

    expect($donor->account_id)->toBeNull()
        ->and(Account::findByEmail('donor@example.com'))->toBeNull()
        ->and(User::find($donor->id))->toBeNull();
});

test('a login resolves through memberships, and every failure looks the same', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);

    $this->post('/login', ['email' => 'Pat@Example.com', 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    auth()->logout();

    $failures = [];
    // Wrong password; no such account; an account that isn't a member here; a locked account.
    $failures[] = $this->post('/login', ['email' => 'pat@example.com', 'password' => 'nope'])->getSession()->get('errors')->first('email');
    $failures[] = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])->getSession()->get('errors')->first('email');
    $stranger = Account::create(['email' => 'stranger@example.com']);
    $stranger->forceFill(['password' => Hash::make('password')])->save();
    $failures[] = $this->post('/login', ['email' => 'stranger@example.com', 'password' => 'password'])->getSession()->get('errors')->first('email');
    $user->account->forceFill(['locked_at' => now()])->save();
    $failures[] = $this->post('/login', ['email' => 'pat@example.com', 'password' => 'password'])->getSession()->get('errors')->first('email');

    $this->assertGuest();
    expect(array_unique($failures))->toHaveCount(1);
});

test('a login whose cached people.account_id disagrees with memberships does not resolve', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $intruder = User::factory()->create();
    // As a crafted restore could: point another person row at Pat's account.
    DB::table('people')->where('id', $user->id)->update(['account_id' => null]);
    DB::table('people')->where('id', $intruder->id)->update(['account_id' => $user->account_id]);

    $this->post('/login', ['email' => 'pat@example.com', 'password' => 'password']);

    $this->assertGuest();
});

test('login attempts are counted per account in the accounts schema', function () {
    User::factory()->create(['email' => 'pat@example.com']);

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'nope']);
    }

    expect(Cache::store('accounts')->get('pat@example.com|127.0.0.1'))->toBe(5);
    $this->post('/login', ['email' => 'pat@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('a password change ends the account\'s other sessions but keeps the one that made it', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->put('/password', [
        'current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
    ])->assertSessionHasNoErrors();
    $this->get('/profile')->assertOk();

    // Another session, still on the old epoch, is ended on its next request.
    $this->withSession([AccountSession::KEY => [$user->account_id, 0]])->get('/profile')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('locking an account ends its sessions', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/profile')->assertOk();

    $user->account->forceFill(['locked_at' => now()])->save();

    $this->get('/profile')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an admin may change a login only where this warehouse is its sole home', function () {
    $admin = userWithPermissions('admin-people');
    $target = User::factory()->create();
    $this->actingAs($admin);

    // A training membership elsewhere doesn't count.
    joinOtherWarehouse($target, training: true);
    $target->forceFill(['password' => Hash::make('changed-by-admin')])->save();
    expect(Hash::check('changed-by-admin', $target->account->fresh()->password))->toBeTrue();

    // A real membership elsewhere does: password, verification and email are all refused.
    joinOtherWarehouse($target);
    $fresh = fn () => Person::find($target->id);
    expect(fn () => $fresh()->forceFill(['password' => Hash::make('again')])->save())->toThrow(ValidationException::class)
        ->and(fn () => $fresh()->forceFill(['email_verified_at' => null])->save())->toThrow(ValidationException::class)
        ->and(fn () => $fresh()->update(['email' => 'elsewhere@example.com']))->toThrow(ValidationException::class);

    // Re-stamping an already verified address, or editing anything local, is fine.
    $fresh()->forceFill(['email_verified_at' => now(), 'disabled_at' => now()])->save();
    expect($fresh()->disabled_at)->not->toBeNull();

    // The owner themselves still can.
    $this->actingAs($target);
    $fresh()->forceFill(['password' => Hash::make('mine')])->save();
    expect(Hash::check('mine', $target->account->fresh()->password))->toBeTrue();
});

test('the contact email mirrors the login email, and a new login cannot take an existing one', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $user->update(['email' => 'new@example.com']);
    expect($user->account->fresh()->email)->toBe('new@example.com');

    // Changed elsewhere (another warehouse): the next login here mirrors it back.
    $user->account->forceFill(['email' => 'elsewhere@example.com'])->save();
    $this->post('/login', ['email' => 'elsewhere@example.com', 'password' => 'password']);
    expect($user->fresh()->email)->toBe('elsewhere@example.com');

    $other = Account::create(['email' => 'taken@example.com']);
    expect(fn () => User::factory()->create(['email' => 'TAKEN@example.com']))->toThrow(ValidationException::class);
});

test('deleting your account leaves this warehouse; the login goes only when nothing else uses it', function () {
    $solo = User::factory()->create();
    $soloAccount = $solo->account_id;
    $this->actingAs($solo)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect(Person::find($solo->id)->account_id)->toBeNull()
        ->and(Account::find($soloAccount))->toBeNull();

    $shared = User::factory()->create();
    $sharedAccount = $shared->account_id;
    $other = joinOtherWarehouse($shared);
    $this->actingAs($shared)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    expect(Person::find($shared->id)->account_id)->toBeNull()
        ->and(Account::find($sharedAccount))->not->toBeNull()
        ->and(Membership::where('account_id', $sharedAccount)->pluck('warehouse_id')->all())->toBe([$other->id]);
});

test('the first administrator and command-line administrators are instance admins; account:instance-admin manages it', function () {
    $this->artisan('admin:bootstrap', ['--email' => 'boss@example.com', '--password' => 'secret-password-1'])->assertSuccessful();
    expect(Account::findByEmail('boss@example.com')->is_instance_admin)->toBeTrue();

    $plain = User::factory()->create(['email' => 'plain@example.com']);
    expect($plain->account->is_instance_admin)->toBeFalse();
    $this->artisan('account:instance-admin', ['email' => 'plain@example.com'])->assertSuccessful();
    expect($plain->account->fresh()->is_instance_admin)->toBeTrue();
    $this->artisan('account:instance-admin', ['email' => 'plain@example.com', '--revoke' => true])->assertSuccessful();
    expect($plain->account->fresh()->is_instance_admin)->toBeFalse();
});

test('password reset tokens live in the accounts schema', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    expect(DB::connection('accounts')->table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse();
});
