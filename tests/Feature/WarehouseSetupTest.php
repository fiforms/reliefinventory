<?php

use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use App\Models\PeopleRoles;
use App\Models\User;
use App\Services\FirstRunService;
use App\Services\Warehouses\SetupCodes;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md §7: handing a new, empty warehouse to whoever holds its one-time setup code.

beforeEach(function () {
    $this->default = Warehouse::default();
    $this->other = SecondWarehouse::make($this, ['status' => 'awaiting_setup', 'display_name' => 'Brand New'])->fresh();
    $this->codes = app(SetupCodes::class);
    [$this->code, $this->codeRow] = $this->codes->issue($this->other, 'test');
    RateLimiter::clear("warehouse-setup:{$this->other->id}");
    // What a browser on this warehouse's setup page sends: its cookie, and (like every page's requests) the warehouse it believes it is on.
    $this->cookie = fn () => $this->withCredentials()->withUnencryptedCookie('ri_warehouse', $this->other->id)->withHeader('X-RI-Warehouse', $this->other->id);
    $this->person = ['first_name' => 'Pat', 'last_name' => 'Admin', 'email' => 'pat@example.org'];
});

afterEach(fn () => app(WarehouseContext::class)->switchTo(Warehouse::default()));

test('a setup code is shown in groups, stored only as a hash, and typed any way', function () {
    expect($this->code)->toMatch('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/')
        ->and($this->codeRow->code_hash)->not->toContain(str_replace('-', '', $this->code))
        ->and($this->codes->find($this->other, strtolower($this->code)))->not->toBeNull()
        ->and($this->codes->find($this->other, str_replace('-', ' ', $this->code)))->not->toBeNull()
        ->and($this->codes->find($this->other, 'AAAAA-AAAAA'))->toBeNull();
});

test('a code works only for its own warehouse, until it expires, is revoked or is replaced', function () {
    expect($this->codes->find($this->default, $this->code))->toBeNull();

    [$newer] = $this->codes->issue($this->other, 'test');
    expect($this->codes->find($this->other, $this->code))->toBeNull()
        ->and($this->codes->find($this->other, $newer))->not->toBeNull();

    $this->travel(8)->days();
    expect($this->codes->find($this->other, $newer))->toBeNull();
    $this->travelBack();

    [$again] = $this->codes->issue($this->other, 'test');
    $this->codes->revoke($this->other);
    expect($this->codes->find($this->other, $again))->toBeNull();
});

test('a warehouse awaiting setup answers only the setup screen', function () {
    ($this->cookie)()->get('/')->assertRedirect('/activate');
    ($this->cookie)()->get('/login')->assertRedirect('/activate');
    ($this->cookie)()->getJson('/json/orders')->assertStatus(503);
    ($this->cookie)()->withoutVite()->get('/activate')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Setup/Activate')->where('warehouseName', 'Brand New')->where('claimed', false));
});

test('its address, /w/<id>/activate, selects it; an unknown id does not exist', function () {
    $this->get('/w/'.$this->other->id.'/activate')->assertRedirect('/activate')->assertCookie('ri_warehouse', $this->other->id, false);
    $this->getJson('/w/ZZZZZZZZ/activate')->assertStatus(404);
});

test('an active warehouse has no setup screen', function () {
    $this->other->forceFill(['status' => 'active'])->save();

    ($this->cookie)()->get('/activate')->assertRedirect(route('login'));
    ($this->cookie)()->post('/activate/code', ['code' => $this->code])->assertNotFound();
});

test('the right code is remembered by the session; a wrong one is not', function () {
    ($this->cookie)()->post('/activate/code', ['code' => 'AAAAA-AAAAA'])->assertSessionHasErrors('code')->assertSessionMissing('setup_claim');
    ($this->cookie)()->post('/activate/code', ['code' => $this->code])->assertRedirect('/activate')->assertSessionHas('setup_claim', $this->codeRow->id);
});

test('too many wrong tries pause the warehouse, even for the right code', function () {
    foreach (range(1, config('warehouses.setup_attempts')) as $i) {
        ($this->cookie)()->post('/activate/code', ['code' => 'AAAAA-AAAAA']);
    }

    ($this->cookie)()->post('/activate/code', ['code' => $this->code])->assertSessionHasErrors('code')->assertSessionMissing('setup_claim');
});

test('without the code nobody can finish setup', function () {
    ($this->cookie)()->post('/activate/setup', $this->person + ['login' => 'new', 'password' => 'a-long-passphrase-1', 'password_confirmation' => 'a-long-passphrase-1'])
        ->assertRedirect('/activate')->assertSessionHasErrors('code');

    expect(app(FirstRunService::class)->administratorExists())->toBeFalse()
        ->and($this->other->fresh()->status)->toBe('awaiting_setup');
});

test('the code holder sets up a new login: Administrator here, never an instance admin, code used up', function () {
    $response = ($this->cookie)()->withSession(['setup_claim' => $this->codeRow->id])
        ->post('/activate/setup', $this->person + ['login' => 'new', 'password' => 'a-long-passphrase-1', 'password_confirmation' => 'a-long-passphrase-1']);

    $response->assertRedirect(route('dashboard'));
    $user = User::where('email', 'pat@example.org')->firstOrFail();
    $account = Account::findByEmail('pat@example.org');
    expect(PeopleRoles::where('person_id', $user->id)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
        ->and($account->is_instance_admin)->toBeFalse()
        ->and(Membership::where('account_id', $account->id)->pluck('warehouse_id')->all())->toBe([$this->other->id])
        ->and($this->other->fresh()->status)->toBe('active')
        ->and($this->codeRow->fresh()->used_at)->not->toBeNull()
        ->and($this->codes->find($this->other, $this->code))->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('an existing login joins as Administrator with its credentials untouched, once its password is proved', function () {
    $account = new Account(['email' => 'sam@example.org']);
    $account->forceFill(['password' => Hash::make('sams-password-1'), 'email_verified_at' => now()])->save();
    $hash = $account->fresh()->password;
    $post = fn (string $password) => ($this->cookie)()->withSession(['setup_claim' => $this->codeRow->id])
        ->post('/activate/setup', ['login' => 'existing', 'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@example.org', 'password' => $password]);

    $post('wrong-password')->assertSessionHasErrors('password');
    expect($this->other->fresh()->status)->toBe('awaiting_setup');

    $post('sams-password-1')->assertRedirect(route('dashboard'));
    $user = User::where('email', 'sam@example.org')->firstOrFail();
    expect($user->account_id)->toBe($account->id)
        ->and($account->fresh()->password)->toBe($hash)
        ->and($account->fresh()->is_instance_admin)->toBeFalse()
        ->and(Membership::where('account_id', $account->id)->where('warehouse_id', $this->other->id)->where('person_id', $user->id)->exists())->toBeTrue()
        ->and(PeopleRoles::where('person_id', $user->id)->where('role_id', FirstRunService::ADMINISTRATOR_ROLE_ID)->exists())->toBeTrue()
        ->and($this->other->fresh()->status)->toBe('active');
});

test('a new login cannot take an email that already has one', function () {
    (new Account(['email' => 'taken@example.org']))->forceFill(['password' => Hash::make('x-long-password-1')])->save();

    ($this->cookie)()->withSession(['setup_claim' => $this->codeRow->id])
        ->post('/activate/setup', ['login' => 'new', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'taken@example.org', 'password' => 'a-long-passphrase-1', 'password_confirmation' => 'a-long-passphrase-1'])
        ->assertSessionHasErrors('email');

    expect($this->other->fresh()->status)->toBe('awaiting_setup');
});

test('revoking the code ends a claim already made', function () {
    $this->codes->revoke($this->other);

    ($this->cookie)()->withSession(['setup_claim' => $this->codeRow->id])
        ->post('/activate/setup', $this->person + ['login' => 'new', 'password' => 'a-long-passphrase-1', 'password_confirmation' => 'a-long-passphrase-1'])
        ->assertRedirect('/activate')->assertSessionHasErrors('code');

    expect($this->other->fresh()->status)->toBe('awaiting_setup')
        ->and(User::where('email', 'pat@example.org')->exists())->toBeFalse();
});

test('a failed setup keeps nothing and leaves the code usable', function () {
    // The warehouse got an Administrator some other way: setup refuses, and the code row is left as it was.
    $other = $this->other;
    app(WarehouseContext::class)->run($other, fn () => app(FirstRunService::class)->createAdministrator('boss@example.org', 'a-long-passphrase-1', 'Boss', 'Man', false));

    ($this->cookie)()->withSession(['setup_claim' => $this->codeRow->id])
        ->post('/activate/setup', $this->person + ['login' => 'new', 'password' => 'a-long-passphrase-1', 'password_confirmation' => 'a-long-passphrase-1'])
        ->assertSessionHasErrors('code');

    expect($this->codeRow->fresh()->used_at)->toBeNull()
        ->and($other->fresh()->status)->toBe('awaiting_setup')
        ->and(SetupCode::usable()->whereKey($this->codeRow->id)->exists())->toBeTrue();
});
