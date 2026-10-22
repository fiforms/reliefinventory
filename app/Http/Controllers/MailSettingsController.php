<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\MailSetting;
use App\Services\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * /setup/email (admin-system): outgoing mail settings and the feedback-report
 * recipient list. A blank field means "use the server's .env value", which the
 * page shows beside it. Secrets are never sent to the browser — only whether
 * one is saved here or set in .env — and a blank secret on save leaves the
 * saved one alone; clearing it is an explicit flag.
 */
class MailSettingsController extends Controller
{
    private const PLAIN = ['mailer', 'from_address', 'from_name', 'smtp_host', 'smtp_port', 'smtp_username', 'feedback_notify_emails'];

    private const SECRETS = ['resend_key', 'smtp_password'];

    public function show(MailSettings $mail)
    {
        $settings = MailSetting::current();
        $defaults = $mail->defaults();

        $saved = [];
        $fallback = [];
        foreach (self::PLAIN as $key) {
            $saved[$key] = $settings->{$key};
            $fallback[$key] = $defaults[$key];
        }
        foreach (self::SECRETS as $key) {
            $saved[$key.'_set'] = filled($settings->{$key});
            $fallback[$key.'_set'] = filled($defaults[$key]);
        }

        return response()->json([
            'settings' => $saved,
            'defaults' => $fallback,
            'effective_mailer' => $mail->effective($settings)['mailer'],
            'mailers' => MailSettings::MAILERS,
            'test_to' => Auth::user()->email,
        ]);
    }

    public function update(Request $request, MailSettings $mail)
    {
        $data = $request->validate([
            'mailer' => ['nullable', Rule::in(MailSettings::MAILERS)],
            'from_address' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'resend_key' => 'nullable|string|max:255',
            'clear_resend_key' => 'boolean',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:255',
            'clear_smtp_password' => 'boolean',
            'feedback_notify_emails' => ['nullable', 'string', 'max:1000', function ($attribute, $value, $fail) {
                foreach (array_filter(array_map('trim', explode(',', $value))) as $email) {
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail("\"{$email}\" is not a valid email address.");
                    }
                }
            }],
        ]);

        $settings = MailSetting::current();
        foreach (self::PLAIN as $key) {
            $settings->{$key} = filled($data[$key] ?? null) ? $data[$key] : null;
        }
        foreach (self::SECRETS as $key) {
            if ($request->boolean('clear_'.$key)) {
                $settings->{$key} = null;
            } elseif (filled($data[$key] ?? null)) {
                $settings->{$key} = $data[$key];
            }
        }
        $settings->save();

        $mail->apply();

        return $this->show($mail);
    }

    /** Send a short test message with the saved settings, reporting the mail service's own error if it refuses. */
    public function test(Request $request, MailSettings $mail)
    {
        $data = $request->validate(['to' => 'required|email|max:255']);

        $mail->apply();
        $effective = $mail->effective();

        try {
            Mail::raw(
                'This is a test message from Relief Inventory at '.config('app.url').".\n\n"
                .'If you can read this, outgoing email is working.',
                fn ($message) => $message->to($data['to'])->subject('Relief Inventory test email')
            );
        } catch (\Throwable $e) {
            Log::warning('Test email failed: '.$e->getMessage());

            return response()->json(['message' => 'The test email was not sent: '.$e->getMessage()], 422);
        }

        $note = $effective['mailer'] === 'log'
            ? 'The mailer is set to "log", so the message was written to the application log, not delivered.'
            : "Sent to {$data['to']} via {$effective['mailer']}. Check that inbox (and its spam folder).";

        return response()->json(['message' => $note]);
    }
}
