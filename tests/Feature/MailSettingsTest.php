<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Mail\FeedbackReportSubmitted;
use App\Models\MailSetting;
use App\Services\MailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Messages captured by the array mailer phpunit.xml selects. */
function sentMessages(): array
{
    return iterator_to_array(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
}

test('show never returns a secret, only whether one is saved here or in .env', function () {
    config(['services.resend.key' => 're_from_env']);
    MailSetting::current()->update(['resend_key' => 're_saved', 'from_address' => 'notify@example.org']);

    $this->actingAs(userWithPermissions('admin-system'))->getJson('/json/mail-settings')
        ->assertOk()
        ->assertJson([
            'settings' => ['from_address' => 'notify@example.org', 'resend_key_set' => true, 'smtp_password_set' => false],
            'defaults' => ['resend_key_set' => true],
        ])
        ->assertJsonMissingPath('settings.resend_key')
        ->assertJsonMissingPath('defaults.resend_key')
        ->assertDontSee('re_saved')
        ->assertDontSee('re_from_env');
});

test('update saves settings, blanks mean use .env, and the key is stored encrypted', function () {
    $this->actingAs(userWithPermissions('admin-system'))->putJson('/json/mail-settings', [
        'mailer' => 'resend',
        'from_address' => 'notify@example.org',
        'from_name' => '',
        'resend_key' => 're_new_key',
        'feedback_notify_emails' => 'a@example.org, b@example.org',
    ])->assertOk()->assertJson(['settings' => [
        'mailer' => 'resend',
        'from_address' => 'notify@example.org',
        'from_name' => null,
        'resend_key_set' => true,
    ]]);

    $settings = MailSetting::current();
    expect($settings->resend_key)->toBe('re_new_key')
        ->and(DB::table('mail_settings')->value('resend_key'))->not->toContain('re_new_key');
});

test('a blank secret keeps the saved one and the clear flag removes it', function () {
    MailSetting::current()->update(['resend_key' => 're_keep', 'smtp_password' => 'pw']);
    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->putJson('/json/mail-settings', ['resend_key' => '', 'smtp_password' => ''])->assertOk();
    expect(MailSetting::current()->resend_key)->toBe('re_keep')
        ->and(MailSetting::current()->smtp_password)->toBe('pw');

    $this->actingAs($admin)->putJson('/json/mail-settings', ['clear_resend_key' => true])->assertOk();
    expect(MailSetting::current()->resend_key)->toBeNull()
        ->and(MailSetting::current()->smtp_password)->toBe('pw');
});

test('update rejects a bad mailer, From address or recipient', function () {
    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->putJson('/json/mail-settings', ['mailer' => 'sendmail'])->assertJsonValidationErrors('mailer');
    $this->actingAs($admin)->putJson('/json/mail-settings', ['from_address' => 'nope'])->assertJsonValidationErrors('from_address');
    $this->actingAs($admin)->putJson('/json/mail-settings', ['feedback_notify_emails' => 'a@example.org, nope'])
        ->assertJsonValidationErrors('feedback_notify_emails');
});

test('saved settings override the .env mail config, and blanks fall back to it', function () {
    config(['mail.from.address' => 'env@example.org', 'mail.from.name' => 'Env Name', 'services.resend.key' => 're_env']);
    MailSetting::current()->update(['from_address' => 'notify@example.org', 'resend_key' => 're_saved', 'smtp_port' => 2525]);

    app(MailSettings::class)->apply();

    expect(config('mail.from.address'))->toBe('notify@example.org')
        ->and(config('mail.from.name'))->toBe('Env Name')
        ->and(config('services.resend.key'))->toBe('re_saved')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.default'))->toBe('array'); // mailer left blank: .env's (phpunit's) choice stands
});

test('mail actually goes out with the saved From address, including after a change', function () {
    MailSetting::current()->update(['from_address' => 'first@example.org']);
    Mail::raw('one', fn ($m) => $m->to('x@example.org'));

    // A long-running process (queue worker) already holds a mailer built from the old settings.
    MailSetting::current()->update(['from_address' => 'second@example.org']);
    app(MailSettings::class)->apply();
    Mail::raw('two', fn ($m) => $m->to('x@example.org'));

    $from = array_map(fn ($sent) => $sent->getOriginalMessage()->getFrom()[0]->getAddress(), sentMessages());
    expect($from)->toContain('second@example.org');
});

test('feedback reports email the recipients saved here', function () {
    Mail::fake();
    config(['feedback.notify_emails' => ['env-dev@example.org']]);
    MailSetting::current()->update(['feedback_notify_emails' => 'saved-dev@example.org']);

    $this->actingAs(userWithPermissions('general-access'))->postJson('/json/feedback-reports', [
        'type' => 'bug',
        'message' => 'Something broke',
        'page_url' => '/items',
    ])->assertSuccessful();

    Mail::assertSent(FeedbackReportSubmitted::class, fn ($mail) => $mail->hasTo('saved-dev@example.org') && ! $mail->hasTo('env-dev@example.org'));
});

test('the test button sends a message and reports the mail service error when it fails', function () {
    $admin = userWithPermissions('admin-system');

    $this->actingAs($admin)->postJson('/json/mail-settings/test', ['to' => 'me@example.org'])
        ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'me@example.org'));
    expect(sentMessages())->not->toBeEmpty();

    // Nothing listens on port 1, so SMTP fails fast with a connection error.
    MailSetting::current()->update(['mailer' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1]);
    $this->actingAs($admin)->postJson('/json/mail-settings/test', ['to' => 'me@example.org'])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'The test email was not sent:'));
});

test('email settings require admin-system', function () {
    $user = userWithPermissions('general-access');

    $this->actingAs($user)->get('/setup/email')->assertForbidden();
    $this->actingAs($user)->getJson('/json/mail-settings')->assertForbidden();
    $this->actingAs($user)->putJson('/json/mail-settings', [])->assertForbidden();
    $this->actingAs($user)->postJson('/json/mail-settings/test', ['to' => 'me@example.org'])->assertForbidden();
});
