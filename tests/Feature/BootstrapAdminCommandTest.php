<?php

use App\Models\PeopleRoles;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates the first administrator with the given password', function () {
    $this->artisan('admin:bootstrap', ['--email' => 'first@example.com', '--password' => 'secret-pass-123'])
        ->assertSuccessful();

    $user = User::where('email', 'first@example.com')->firstOrFail();
    expect(Hash::check('secret-pass-123', $user->password))->toBeTrue()
        ->and($user->approved_at)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(PeopleRoles::where('person_id', $user->id)->where('role_id', 15)->exists())->toBeTrue();
});

test('generates and prints a password when none is given', function () {
    $this->artisan('admin:bootstrap', ['--email' => 'gen@example.com'])
        ->expectsOutputToContain('Generated password')
        ->assertSuccessful();

    expect(User::where('email', 'gen@example.com')->exists())->toBeTrue();
});

test('does nothing once an administrator exists', function () {
    $this->artisan('admin:bootstrap', ['--email' => 'first@example.com', '--password' => 'x-secret-1234'])->assertSuccessful();
    $this->artisan('admin:bootstrap', ['--email' => 'second@example.com', '--password' => 'x-secret-1234'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
});

test('fails without an email when no administrator exists', function () {
    $this->artisan('admin:bootstrap')->assertFailed();
});

test('git version prefers the commit baked into a docker image', function () {
    config(['app.git_commit' => 'abcdef1234567890']);

    expect(app(\App\Services\GitVersionService::class)->currentCommit())->toBe('abcdef123');
});
