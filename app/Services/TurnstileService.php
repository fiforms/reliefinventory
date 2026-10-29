<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\Control\WarehouseDomain;
use App\Models\OfflineModeSetting;
use Illuminate\Http\Response;
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
    public function misconfiguredResponse(): Response
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

        $this->confirmHost($response);
    }

    /**
     * Cloudflare names the hostname a passed check came from. When it is a mapped warehouse hostname, remember that it works
     * (MULTI_WAREHOUSE.md §13), so the domain list can stop warning that the host may not be on the widget. Never throws.
     */
    private function confirmHost(array $response): void
    {
        try {
            $host = strtolower((string) ($response['hostname'] ?? ''));
            if ($host !== '' && $host === strtolower(request()->getHost())) {
                WarehouseDomain::where('hostname', $host)->whereNull('turnstile_confirmed_at')->update(['turnstile_confirmed_at' => now()]);
            }
        } catch (\Throwable) {
        }
    }
}
