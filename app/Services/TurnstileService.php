<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\OfflineModeSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cloudflare Turnstile, in one place for every form that uses it
 * (registration, password reset, public forms). Previously each controller
 * carried its own copy, and they disagreed on what "enabled but no keys
 * set" meant — the public form rendered an empty widget while still
 * demanding a token, so nothing could ever be submitted (feedback #45).
 *
 * enabled() is the on/off decision (config switch + offline mode);
 * configured() is whether the keys exist to actually run it. Enabled but
 * not configured is a server misconfiguration: callers refuse rather than
 * silently skipping the check, since skipping would quietly drop bot
 * protection from a public, unauthenticated form.
 */
class TurnstileService
{
    public function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled') && ! OfflineModeSetting::isOffline();
    }

    public function configured(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /** Enabled but missing keys — logged so it shows up for an admin, not just the visitor. */
    public function misconfigured(): bool
    {
        if ($this->enabled() && ! $this->configured()) {
            Log::error('Cloudflare Turnstile is enabled but CLOUDFLARE_TURNSTILE_SITE_KEY/SECRET_KEY are not both set.');

            return true;
        }

        return false;
    }

    /**
     * What a page shows when misconfigured() is true: a plain 503 that tells
     * the administrator which settings to change, instead of a bare 500.
     */
    public function misconfiguredResponse(): \Illuminate\Http\Response
    {
        return response()->view('errors.turnstile-misconfigured', [], 503);
    }

    public function siteKey(): ?string
    {
        return config('services.turnstile.site_key');
    }

    /**
     * @throws ValidationException when Cloudflare doesn't confirm the token
     *                             (including when it can't be reached).
     */
    public function verify(?string $token, ?string $ip): void
    {
        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret_key'),
            'response' => $token,
            'remoteip' => $ip,
        ])->json();

        if (! ($response['success'] ?? false)) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => ['Failed Turnstile verification. Please try again.'],
            ]);
        }
    }
}
