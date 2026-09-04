<?php

use App\Models\User;

test('a newly registered, unapproved account is bounced off the dashboard to registration-pending', function () {
    $response = $this->post('/register', [
        'first_name' => 'Josh',
        'last_name' => 'Green',
        'email' => 'josh@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(User::where('email', 'josh@example.com')->first()->approved_at)->toBeNull();

    // Registration redirects to /dashboard, but the same session hitting
    // that URL must never actually reach it while unapproved — this is
    // the exact gap that let a verified-but-unreviewed account see the
    // real app shell.
    $response->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard', absolute: false))
        ->assertRedirect(route('registration.pending', absolute: false));
});

test('a pending account cannot reach a permission-gated json endpoint even with no permission required', function () {
    $user = User::factory()->pendingApproval()->create();

    $this->actingAs($user)->getJson('/json/menu-data')->assertStatus(403);
});

test('a pending account can still reach the registration track and pending pages', function () {
    $user = User::factory()->pendingApproval()->create();

    $this->actingAs($user)->get(route('registration.track', absolute: false))->assertStatus(200);

    $user->update(['requested_track' => 'volunteer']);
    $this->actingAs($user)->get(route('registration.pending', absolute: false))->assertStatus(200);
});

test('an approved account is unaffected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard', absolute: false))->assertStatus(200);
});
