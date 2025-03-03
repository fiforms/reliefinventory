<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        $turnstileSiteKey = env('CLOUDFLARE_TURNSTILE_SITE_KEY');
        
        if (empty($turnstileSiteKey)) {
            abort(500, 'Cloudflare Turnstile is not properly configured.');
        }
        
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
            'turnstile_site_key' => $turnstileSiteKey,
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'cf-turnstile-response' => 'required', // Ensure Turnstile response exists
        ]);
        
        // Verify Turnstile response with Cloudflare
        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
            'response' => $request->input('cf-turnstile-response'),
            'remoteip' => $request->ip(),
        ])->json();
        
        // If Turnstile verification fails
        if (!$response['success']) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => ['Failed Turnstile verification. Please try again.'],
            ]);
        }

        // Introduce a slight random delay (between 500ms and 4500ms) to prevent timing attacks
        usleep(random_int(500000, 4500000));
        
        // Always return the same response to avoid user enumeration
        Password::sendResetLink($request->only('email'));
        
        return back()->with('status', __('If the email exists, a reset link has been sent.'));
        
    }
}
