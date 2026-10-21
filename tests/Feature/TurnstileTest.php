<?php

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

// Feedback #45: Turnstile switched on with no keys used to render an empty
// widget on public forms while still demanding a token, so nothing could
// be submitted and nothing explained why.

function turnstileForm(): Form
{
    $form = Form::create([
        'name' => 'Partner Intake',
        'slug' => 'partner-intake-'.uniqid(),
        'status' => Form::STATUS_ACTIVE,
        'access_mode' => Form::ACCESS_BOTH,
        'requires_approval' => false,
        'on_approval_action' => Form::APPROVAL_NONE,
    ]);
    $form->questions()->create(['order' => 0, 'label' => 'Organization', 'type' => 'short_text', 'required' => false]);

    return $form;
}

test('a public form tells the visitor when Turnstile is enabled but has no keys', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    $form = turnstileForm();

    $this->get("/forms/{$form->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('turnstile_enabled', false)
            ->where('turnstile_unavailable', true));

    $this->postJson("/forms/{$form->slug}", ['answers' => ['x' => 'y'], 'submitter_name' => 'Jane'])
        ->assertStatus(503)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'spam protection'));
    expect(FormSubmission::count())->toBe(0);
});

test('a public form requires and verifies a Turnstile token when keys are set', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    $form = turnstileForm();

    $this->get("/forms/{$form->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('turnstile_enabled', true)
            ->where('turnstile_site_key', 'site')
            ->where('turnstile_unavailable', false));

    $this->postJson("/forms/{$form->slug}", ['answers' => ['x' => 'y'], 'submitter_name' => 'Jane'])
        ->assertStatus(422)->assertJsonValidationErrors('cf-turnstile-response');

    Http::fake(['challenges.cloudflare.com/*' => fn ($request) => Http::response(['success' => $request['response'] === 'good'])]);
    $this->postJson("/forms/{$form->slug}", ['answers' => ['x' => 'y'], 'submitter_name' => 'Jane', 'cf-turnstile-response' => 'bad'])
        ->assertStatus(422)->assertJsonValidationErrors('cf-turnstile-response');

    $this->postJson("/forms/{$form->slug}", ['answers' => ['x' => 'y'], 'submitter_name' => 'Jane', 'cf-turnstile-response' => 'good'])
        ->assertOk();
    expect(FormSubmission::count())->toBe(1);
});

test('an unreachable Cloudflare fails verification instead of erroring', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response('bad gateway', 502)]);

    $this->postJson('/forgot-password', ['email' => 'nobody@example.org', 'cf-turnstile-response' => 'tok'])
        ->assertStatus(422)->assertJsonValidationErrors('cf-turnstile-response');
});

test('registration and password reset explain a Turnstile with no keys instead of a bare 500', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    foreach (['/register', '/forgot-password'] as $url) {
        $this->get($url)->assertStatus(503)->assertSee('CLOUDFLARE_TURNSTILE_ENABLED', false);
    }
    $this->post('/register', [])->assertStatus(503);
});

test('registration page loads when Turnstile is switched off', function () {
    config(['services.turnstile.enabled' => false]);

    $this->get('/register')->assertOk();
});

test('register and forgot-password links are hidden only while Turnstile is enabled without keys', function () {
    config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    $this->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('canRegister', false));
    $this->get('/login')->assertInertia(fn (AssertableInertia $p) => $p->where('canResetPassword', false));

    config(['services.turnstile.enabled' => false]);
    $this->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('canRegister', true));
    $this->get('/login')->assertInertia(fn (AssertableInertia $p) => $p->where('canResetPassword', true));
});
