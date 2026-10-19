<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TurnstileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(TurnstileService $turnstile): Response
    {
        $turnstileEnabled = $turnstile->enabled();
        $turnstileSiteKey = $turnstile->siteKey();

        if ($turnstile->misconfigured()) {
            abort(500, 'Cloudflare Turnstile is not properly configured.');
        }

        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
            'turnstile_enabled' => $turnstileEnabled,
            'turnstile_site_key' => $turnstileSiteKey,
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, TurnstileService $turnstile): RedirectResponse
    {
        $turnstileEnabled = $turnstile->enabled();
        if ($turnstile->misconfigured()) {
            abort(500, 'Cloudflare Turnstile is not properly configured.');
        }

        $request->validate([
            'email' => 'required|email',
            'cf-turnstile-response' => $turnstileEnabled ? 'required' : 'nullable',
        ]);

        if ($turnstileEnabled) {
            $turnstile->verify($request->input('cf-turnstile-response'), $request->ip());
        }

        // Introduce a slight random delay (between 500ms and 4500ms) to prevent timing attacks
        usleep(random_int(500000, 4500000));

        // Always return the same response to avoid user enumeration
        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('If the email exists, a reset link has been sent.'));

    }
}
