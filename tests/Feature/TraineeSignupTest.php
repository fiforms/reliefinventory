<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Trainee self sign-up at /join: public, only while Training Mode is on, never logs anyone in, and a whole room
// on one Wi-Fi network (one IP address) can sign up at once.

use App\Models\Person;
use App\Models\TrainingRegistration;
use App\Services\TrainingMode;
use Illuminate\Support\Facades\Http;

function signupOpen(): void
{
    trainingWarehouse();
}

test('sign-up is closed unless Training Mode is on', function () {
    $this->get('/join')->assertOk()->assertInertia(fn ($page) => $page->component('Join')->where('open', false));
    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able'])->assertForbidden();
    expect(Person::where('is_trainee', true)->count())->toBe(0);
});

test('a sign-up puts the person on the trainee list with their answers, without logging them in', function () {
    signupOpen();

    $this->postJson('/join', [
        'first_name' => ' Ann ', 'last_name' => 'Able', 'email' => 'ann@example.org', 'phone' => '555-1234',
        'wants_updates' => true, 'help_software' => true, 'recommend' => 'Bo Baker, 555-9876', 'comments' => 'Great talk',
    ])->assertCreated()->assertJsonPath('first_name', 'Ann');

    $this->assertGuest();
    $person = Person::where('first_name', 'Ann')->sole();
    expect($person->is_trainee)->toBeTrue()
        ->and($person->roles()->pluck('name')->all())->toBe([TrainingMode::ROLE])
        ->and($person->email)->toEndWith('@training.invalid')
        ->and($person->phone)->toBe('555-1234');
    expect(TrainingRegistration::sole()->only(['email', 'wants_updates', 'help_volunteer', 'help_software', 'recommend', 'comments']))->toBe([
        'email' => 'ann@example.org', 'wants_updates' => true, 'help_volunteer' => false, 'help_software' => true,
        'recommend' => 'Bo Baker, 555-9876', 'comments' => 'Great talk',
    ]);
});

test('signing up again with the same name updates that trainee, keeping earlier answers', function () {
    signupOpen();
    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able', 'email' => 'ann@example.org'])->assertCreated();
    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able', 'help_volunteer' => true])->assertCreated();

    expect(Person::where('first_name', 'Ann')->count())->toBe(1)
        ->and(TrainingRegistration::count())->toBe(1)
        ->and(TrainingRegistration::sole()->only(['email', 'help_volunteer']))->toBe(['email' => 'ann@example.org', 'help_volunteer' => true]);
});

test('only a first and last name are required', function () {
    signupOpen();
    $this->postJson('/join', ['first_name' => 'Ann'])->assertUnprocessable()->assertJsonValidationErrors('last_name');
    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
});

test('a full room on one IP address can sign up', function () {
    signupOpen();
    foreach (range(1, 60) as $i) {
        $this->postJson('/join', ['first_name' => 'Person', 'last_name' => "Number $i"])->assertCreated();
    }
    expect(Person::where('is_trainee', true)->count())->toBe(60);
});

test('Turnstile is required when it is switched on', function () {
    signupOpen();
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able'])->assertJsonValidationErrors('cf-turnstile-response');
    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able', 'cf-turnstile-response' => 'tok'])->assertCreated();
});

test('the Training Mode page shows the sign-up link and QR code, and the follow-up list exports', function () {
    signupOpen();
    $this->postJson('/join', ['first_name' => 'Ann', 'last_name' => 'Able', 'email' => 'ann@example.org', 'wants_updates' => true]);
    $trainer = userWithPermissions('manage-training');

    $this->actingAs($trainer)->getJson('/json/training/status')
        ->assertJsonPath('signup.url', route('join'))->assertJsonPath('signup.today', 1)
        ->assertJson(fn ($json) => $json->where('signup.qr_svg', fn ($svg) => str_starts_with($svg, '<svg'))->etc());

    $csv = $this->actingAs($trainer)->get('/report/training/signups.csv')->assertOk()->getContent();
    expect($csv)->toContain('ann@example.org')->toContain('Able');
    $this->actingAs($trainer)->get('/report/training/signup-poster.pdf')->assertOk();
    $this->actingAs(userWithPermissions('general-access'))->get('/report/training/signups.csv')->assertForbidden();
});
