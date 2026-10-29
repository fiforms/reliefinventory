<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;

/**
 * Merges the /setup/email settings (MailSetting) over the .env mail config.
 *
 * A null setting falls back to the .env/config value (snapshotted the first
 * time apply() overrides it, so the settings page can always show what
 * "blank" falls back to).
 * apply() is called when the mail manager is first resolved and before every
 * queued job (AppServiceProvider), so a long-running queue worker picks up a
 * change without a restart. It never throws: with no table yet (fresh install,
 * mid-migration) the .env config is simply left alone.
 */
class MailSettings
{
    /** Mailers the settings page offers. Anything else (ses, array, ...) can still be chosen in .env. */
    public const MAILERS = ['resend', 'smtp', 'log'];

    /** The .env mail config as it was just before apply() first overrode it; null until then. */
    private ?array $envConfig = null;

    /** The .env-derived value of every setting. */
    public function defaults(): array
    {
        return ($this->envConfig ?? $this->readConfig()) + [
            // never written by apply(), so always read live
            'feedback_notify_emails' => implode(', ', (array) config('feedback.notify_emails', [])),
        ];
    }

    /** Saved settings over the .env defaults; blank strings count as unset. */
    public function effective(?MailSetting $settings = null): array
    {
        $settings ??= $this->load();
        $effective = $this->defaults();
        if ($settings) {
            foreach (array_keys($effective) as $key) {
                if (filled($settings->{$key})) {
                    $effective[$key] = $settings->{$key};
                }
            }
        }

        return $effective;
    }

    /** Push the effective settings into config and drop any mailer already built from the old ones. */
    public function apply(?MailManager $manager = null): void
    {
        $settings = $this->load();
        if (! $settings) {
            return;
        }
        try {
            $e = $this->effective($settings);
        } catch (\Throwable $ex) {
            // e.g. a secret encrypted under a different APP_KEY — keep the .env config rather than break every page that mails
            Log::error('Mail settings could not be read; using .env mail config: '.$ex->getMessage());

            return;
        }

        $this->envConfig ??= $this->readConfig();
        config([
            'mail.default' => $e['mailer'],
            'mail.from.address' => $e['from_address'],
            'mail.from.name' => $e['from_name'],
            'services.resend.key' => $e['resend_key'],
            'mail.mailers.smtp.host' => $e['smtp_host'],
            'mail.mailers.smtp.port' => $e['smtp_port'],
            'mail.mailers.smtp.username' => $e['smtp_username'],
            'mail.mailers.smtp.password' => $e['smtp_password'],
        ]);

        $manager ??= app()->resolved('mail.manager') ? app('mail.manager') : null;
        $manager?->forgetMailers();
    }

    /** Who gets emailed about a new in-app feedback report. */
    public function feedbackRecipients(): array
    {
        try {
            $list = $this->effective()['feedback_notify_emails'];
        } catch (\Throwable) {
            $list = $this->defaults()['feedback_notify_emails'];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $list))));
    }

    private function readConfig(): array
    {
        $smtp = config('mail.mailers.smtp', []);

        return [
            'mailer' => config('mail.default'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'resend_key' => config('services.resend.key'),
            'smtp_host' => $smtp['host'] ?? null,
            'smtp_port' => isset($smtp['port']) ? (int) $smtp['port'] : null,
            'smtp_username' => $smtp['username'] ?? null,
            'smtp_password' => $smtp['password'] ?? null,
        ];
    }

    private function load(): ?MailSetting
    {
        try {
            return MailSetting::find(1);
        } catch (\Throwable) {
            return null;
        }
    }
}
