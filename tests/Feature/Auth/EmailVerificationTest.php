<?php

use App\Models\Control\Warehouse;
use App\Models\User;
use App\Support\InstanceSignedUrl;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('registration.track', absolute: false));
});

test('verifying redirects straight to pending once a track was already chosen', function () {
    $user = User::factory()->unverified()->create(['requested_track' => 'volunteer']);

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    $response->assertRedirect(route('registration.pending', absolute: false));
});

test('verifying goes to the track picker even with a stale intended URL in session', function () {
    // Reproduces a real bug: visiting /dashboard while a guest stashes it
    // as the post-login "intended" URL. If verification honored that, a
    // brand-new account would land straight on the dashboard instead of
    // ever seeing the track picker.
    $user = User::factory()->unverified()->create();

    $this->withSession(['url.intended' => route('dashboard', absolute: false)]);

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    $response->assertRedirect(route('registration.track', absolute: false));
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the emailed link verifies without being logged in, then sends them to log in', function () {
    // Reproduces a real cs26 bug: the link sat behind `auth`, and an
    // unverified account can't log in, so new users looped back to /login.
    $user = User::factory()->unverified()->create(['disabled_at' => now()]);

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->get($verificationUrl);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
    $response->assertSessionHas('url.intended', route('registration.track', absolute: false));
    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    expect($user->fresh()->disabled_at)->toBeNull();

    // Logging in afterwards lands on the track picker.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('registration.track', absolute: false));
});

test('an expired link sends them to log in instead of a bare 403', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->get($verificationUrl)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the link does not verify another account while someone else is logged in', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->create();

    $verificationUrl = InstanceSignedUrl::generator()->temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($other)->get($verificationUrl)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('logging in unverified stays on the login page and emails a fresh link, at most once a minute', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('login'));
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

test('a link signed with the warehouse key is refused: verification is signed with APP_KEY', function () {
    // A warehouse admin can read their WAREHOUSE_KEY, so a link it signs could be forged (MULTI_WAREHOUSE.md §5).
    $user = User::factory()->unverified()->create();
    expect(Warehouse::default()->key())->not->toBe(config('app.key'));

    $forged = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($forged)->assertRedirect(route('login'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
