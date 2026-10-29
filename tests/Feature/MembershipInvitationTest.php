<?php

use App\Mail\MembershipInvitationMail;
use App\Models\Accounts\Account;
use App\Models\Accounts\Membership;
use App\Models\Accounts\MembershipInvitation;
use App\Models\Control\Warehouse;
use App\Models\Person;
use App\Models\User;
use App\Services\InvitationRefused;
use App\Services\MembershipInvitations;
use App\Services\Warehouses\WarehouseAccounts;
use App\Services\Warehouses\WarehouseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\SecondWarehouse;

// MULTI_WAREHOUSE.md §6: attaching an existing login to a person in a warehouse is an offer its owner accepts.

beforeEach(function () {
    $this->default = Warehouse::default();
    $this->other = SecondWarehouse::make($this, ['display_name' => 'Elsewhere'])->fresh();
    $this->service = app(MembershipInvitations::class);
    $this->context = app(WarehouseContext::class);

    // A login that belongs to the default warehouse (and nowhere else), with a known password.
    $this->sam = User::factory()->create(['email' => 'sam@example.org']);
    $this->account = $this->sam->account;
    $this->account->forceFill(['password' => Hash::make('sams-password-1'), 'email_verified_at' => now()])->save();

    // A person in the other warehouse that could become Sam.
    $this->person = $this->context->run($this->other, fn () => Person::create(['first_name' => 'Sam', 'last_name' => 'Elsewhere', 'email' => 'sam@example.org', 'approved_at' => now()]));
    $this->invite = fn (array $args = []) => $this->service->invite(...array_replace([$this->other, $this->person->id, 'sam@example.org', 'admin', 'Pat Admin'], $args));
    // What a browser on each warehouse's address sends: with several warehouses, every request names its own (the tab guard).
    $this->here = fn (User $user) => $this->actingAs($user)->withHeader('X-RI-Warehouse', $this->default->id);
    // What a browser on the other warehouse's address sends.
    $this->there = fn () => $this->withCredentials()->withUnencryptedCookie('ri_warehouse', $this->other->id)->withHeader('X-RI-Warehouse', $this->other->id);
    RateLimiter::clear('invitation-account:'.$this->account->id);
});

afterEach(fn () => $this->context->switchTo(Warehouse::default()));

test('an invitation is for the login that already exists, and does nothing to it until accepted', function () {
    ['invitation' => $invitation, 'mailed' => $mailed] = ($this->invite)();

    expect($invitation->status)->toBe('pending')->and($invitation->email)->toBe('sam@example.org')->and($mailed)->toBeNull()
        ->and($invitation->token_hash)->toBeNull()
        ->and(Membership::where('account_id', $this->account->id)->pluck('warehouse_id')->all())->toBe([$this->default->id])
        ->and($this->context->run($this->other, fn () => DB::table('people')->where('id', $this->person->id)->value('account_id')))->toBeNull();
});

test('an emailed invitation carries a link on the warehouse address, and only the hash of its token is kept', function () {
    Mail::fake();
    ['invitation' => $invitation, 'mailed' => $mailed] = ($this->invite)([5 => true]);

    expect($mailed)->toBeTrue()->and($invitation->token_hash)->toHaveLength(64);
    Mail::assertSent(MembershipInvitationMail::class, function ($mail) use ($invitation) {
        preg_match('#/invitations/([A-Za-z0-9]{40})#', $mail->url, $m);

        return $mail->hasTo('sam@example.org') && $m && MembershipInvitation::hashToken($m[1]) === $invitation->token_hash
            && ! str_contains($invitation->token_hash, $m[1]) && $mail->warehouse->is($this->other);
    });
});

test('it refuses a login that already belongs to the warehouse, and inviting again renews the offer', function () {
    ($this->invite)();
    $first = MembershipInvitation::first();
    $this->travel(5)->days();
    ($this->invite)();
    expect(MembershipInvitation::count())->toBe(1)->and(MembershipInvitation::first()->expires_at->gt($first->expires_at))->toBeTrue();
    $this->travelBack();

    Membership::create(['account_id' => $this->account->id, 'warehouse_id' => $this->other->id, 'person_id' => 77]);
    expect(fn () => ($this->invite)())->toThrow(InvitationRefused::class);
});

test('a login sees only its own open invitations, for warehouses that are up', function () {
    ($this->invite)();
    $stranger = Account::create(['email' => 'else@example.org']);
    expect($this->service->listFor($stranger))->toBe([]);

    $list = $this->service->listFor($this->account);
    expect($list)->toHaveCount(1)->and($list[0])->toMatchArray(['warehouse' => 'Elsewhere', 'person' => 'Sam Elsewhere', 'source' => 'admin']);

    $this->other->forceFill(['status' => 'suspended'])->save();
    expect($this->service->listFor($this->account))->toBe([]);
});

test('accepting links the person and makes the membership; credentials and instance-admin flag stay as they were', function () {
    $hash = $this->account->password;
    ['invitation' => $invitation] = ($this->invite)();

    $membership = $this->service->accept($invitation, $this->account);

    expect($membership->warehouse_id)->toBe($this->other->id)->and($membership->person_id)->toBe($this->person->id)
        ->and($invitation->fresh()->status)->toBe('accepted')
        ->and($this->account->fresh()->password)->toBe($hash)->and($this->account->fresh()->is_instance_admin)->toBeFalse()
        ->and($this->context->run($this->other, fn () => DB::table('people')->where('id', $this->person->id)->value('account_id')))->toBe($this->account->id);

    expect(fn () => $this->service->accept($invitation, $this->account))->toThrow(InvitationRefused::class);
});

test('accepting is refused for another login, an expired offer, a trainee or a person who has a login now', function () {
    ['invitation' => $invitation] = ($this->invite)();
    $other = Account::create(['email' => 'else@example.org']);
    expect(fn () => $this->service->accept($invitation, $other))->toThrow(InvitationRefused::class);

    $this->travel(15)->days();
    expect(fn () => $this->service->accept($invitation, $this->account))->toThrow(InvitationRefused::class);
    $this->travelBack();

    // The person got a login some other way since: nothing is attached and no membership is made.
    $this->context->run($this->other, fn () => DB::table('people')->where('id', $this->person->id)->update(['account_id' => 999]));
    expect(fn () => $this->service->accept($invitation, $this->account))->toThrow(InvitationRefused::class)
        ->and(Membership::where('warehouse_id', $this->other->id)->count())->toBe(0)
        ->and($invitation->fresh()->status)->toBe('pending');

    $this->context->run($this->other, fn () => DB::table('people')->where('id', $this->person->id)->update(['account_id' => null, 'is_trainee' => true]));
    expect(fn () => $this->service->accept($invitation, $this->account))->toThrow(InvitationRefused::class);
});

test('declining and withdrawing end an offer; only the invited login can decline', function () {
    ['invitation' => $invitation] = ($this->invite)();
    $this->service->decline($invitation, Account::create(['email' => 'else@example.org']));
    expect($invitation->fresh()->status)->toBe('pending');

    $this->service->decline($invitation, $this->account);
    expect($invitation->fresh()->status)->toBe('declined');

    ['invitation' => $again] = ($this->invite)();
    expect($again->status)->toBe('pending');
    $this->service->withdraw($again);
    expect($again->fresh()->status)->toBe('withdrawn')->and($this->service->listFor($this->account))->toBe([]);
});

test('the emailed link: a wrong password changes nothing; the right one joins and signs in, once', function () {
    Mail::fake();
    ($this->invite)([5 => true]);
    $url = null;
    Mail::assertSent(MembershipInvitationMail::class, function ($mail) use (&$url) {
        $url = parse_url($mail->url, PHP_URL_PATH);

        return true;
    });

    ($this->there)()->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Auth/AcceptInvitation')->where('warehouseName', 'Elsewhere')->where('email', 'sam@example.org'));

    ($this->there)()->post($url, ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(Membership::where('warehouse_id', $this->other->id)->count())->toBe(0);

    ($this->there)()->post($url, ['password' => 'sams-password-1'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(User::find($this->person->id));
    expect(Membership::where('account_id', $this->account->id)->pluck('warehouse_id')->sort()->values()->all())->toBe(collect([$this->default->id, $this->other->id])->sort()->values()->all());

    ($this->there)()->get($url)->assertNotFound();
});

test('a link belongs to its own warehouse, and too many wrong passwords stop the guessing', function () {
    Mail::fake();
    ($this->invite)([5 => true]);
    $url = null;
    Mail::assertSent(MembershipInvitationMail::class, function ($mail) use (&$url) {
        $url = parse_url($mail->url, PHP_URL_PATH);

        return true;
    });

    $this->get($url)->assertNotFound();   // the default warehouse's address

    foreach (range(1, 5) as $i) {
        ($this->there)()->post($url, ['password' => 'wrong'.$i]);
    }
    ($this->there)()->post($url, ['password' => 'sams-password-1'])->assertSessionHasErrors('password');
    expect(Membership::where('warehouse_id', $this->other->id)->count())->toBe(0);
    RateLimiter::clear('invitation:'.MembershipInvitation::first()->id);
});

test('in the app: the login signed in elsewhere sees the invitation and accepts it with its password', function () {
    ($this->invite)();

    ($this->here)($this->sam)->get('/account/invitations')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Account/Invitations')->has('invitations', 1)->where('invitations.0.warehouse', 'Elsewhere'));

    $id = MembershipInvitation::first()->id;
    ($this->here)($this->sam)->post("/account/invitations/{$id}/accept", ['password' => 'nope'])->assertSessionHasErrors('password');
    expect(Membership::where('warehouse_id', $this->other->id)->count())->toBe(0);

    ($this->here)($this->sam)->post("/account/invitations/{$id}/accept", ['password' => 'sams-password-1'])
        ->assertRedirect(route('account.invitations'))->assertSessionHas('joined.name', 'Elsewhere');
    expect(Membership::where('warehouse_id', $this->other->id)->where('account_id', $this->account->id)->exists())->toBeTrue();
});

test('in the app: nobody can answer someone else\'s invitation', function () {
    ($this->invite)();
    $id = MembershipInvitation::first()->id;
    $mallory = User::factory()->create(['email' => 'mallory@example.org']);
    $mallory->account->forceFill(['password' => Hash::make('mallory-password-1'), 'email_verified_at' => now()])->save();

    ($this->here)($mallory)->post("/account/invitations/{$id}/accept", ['password' => 'mallory-password-1'])->assertNotFound();
    ($this->here)($mallory)->post("/account/invitations/{$id}/decline")->assertNotFound();
    expect(MembershipInvitation::first()->status)->toBe('pending');
});

test('the profile menu counts waiting invitations only for the invited login', function () {
    ($this->here)($this->sam)->get('/account/invitations')->assertInertia(fn ($p) => $p->where('pendingInvitations', 0));
    ($this->invite)();
    ($this->here)($this->sam)->get('/account/invitations')->assertInertia(fn ($p) => $p->where('pendingInvitations', 1));
});

test('adding a user whose email already has a login invites them instead, with the roles chosen, and emails the link', function () {
    Mail::fake();
    $admin = userWithPermissions('manage-users');
    $this->context->switchTo($this->default);
    // A role that grants nothing, so the acting admin can hand it out (no escalation).
    $roleId = DB::table('roles')->whereNotIn('id', DB::table('role_permissions')->select('role_id'))->value('id');

    // Another warehouse's person is invited to the default warehouse, as an admin there would from the Users page.
    $other = Account::create(['email' => 'rae@example.org']);
    $other->forceFill(['password' => Hash::make('raes-password-1'), 'email_verified_at' => now()])->save();
    Membership::create(['account_id' => $other->id, 'warehouse_id' => $this->other->id, 'person_id' => 4242]);

    ($this->here)($admin)->postJson('/json/users', [
        'first_name' => 'Rae', 'last_name' => 'Visitor', 'email' => 'rae@example.org', 'password' => 'a-typed-password-1',
        'people_roles' => [['role_id' => $roleId]],
    ])->assertCreated()->assertJsonPath('invited', true);

    $person = Person::where('email', 'rae@example.org')->firstOrFail();
    expect($person->account_id)->toBeNull()
        ->and(Membership::where('account_id', $other->id)->where('warehouse_id', $this->default->id)->exists())->toBeFalse()
        ->and($other->fresh()->password)->not->toBe(null)->and(Hash::check('raes-password-1', $other->fresh()->password))->toBeTrue()
        ->and(DB::table('people_roles')->where('person_id', $person->id)->where('role_id', $roleId)->exists())->toBeTrue()
        ->and(MembershipInvitation::where('warehouse_id', $this->default->id)->where('person_id', $person->id)->where('source', 'admin')->exists())->toBeTrue();
    Mail::assertSent(MembershipInvitationMail::class, fn ($mail) => $mail->hasTo('rae@example.org'));

    $listed = ($this->here)($admin)->getJson('/json/users/invitations')->assertOk()->json();
    expect($listed)->toHaveCount(1)->and($listed[0]['email'])->toBe('rae@example.org');

    ($this->here)($admin)->deleteJson('/json/users/invitations/'.$listed[0]['id'])->assertOk();
    expect(($this->here)($admin)->getJson('/json/users/invitations')->json())->toBe([]);
});

test('an untrusted restore invites existing logins, makes new ones unverified and never an instance admin', function () {
    $restored = $this->context->run($this->other, function () {
        Person::whereKey($this->person->id)->delete();   // the restored backup brings its own people
        $existing = Person::create(['first_name' => 'Sam', 'last_name' => 'Restored', 'email' => 'sam@example.org']);
        $fresh = Person::create(['first_name' => 'New', 'last_name' => 'Restored', 'email' => 'newbie@example.org']);
        $report = app(WarehouseAccounts::class)->relinkAfterRestore(['accounts' => [
            ['person_id' => $existing->id, 'email' => 'sam@example.org', 'password' => 'from-the-backup', 'email_verified_at' => now()->toIso8601String()],
            ['person_id' => $fresh->id, 'email' => 'newbie@example.org', 'password' => Hash::make('newbie-password-1'), 'email_verified_at' => now()->toIso8601String()],
        ]], untrusted: true);
        DB::table('people_roles')->insert(['person_id' => $fresh->id, 'role_id' => 15]);

        return [$existing, $fresh, $report];
    });
    [$existing, $fresh] = $restored;
    $newAccount = Account::findByEmail('newbie@example.org');

    expect(Membership::where('account_id', $this->account->id)->where('warehouse_id', $this->other->id)->exists())->toBeFalse()
        ->and(Hash::check('sams-password-1', $this->account->fresh()->password))->toBeTrue()
        ->and(MembershipInvitation::where('warehouse_id', $this->other->id)->where('person_id', $existing->id)->where('source', 'restore')->where('email', 'sam@example.org')->exists())->toBeTrue()
        ->and($newAccount->email_verified_at)->toBeNull()
        ->and(Membership::where('account_id', $newAccount->id)->where('warehouse_id', $this->other->id)->exists())->toBeTrue()
        ->and(Account::where('is_instance_admin', true)->where('id', $newAccount->id)->exists())->toBeFalse();
});
