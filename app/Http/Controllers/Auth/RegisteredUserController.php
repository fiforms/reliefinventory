<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BannedEmail;
use App\Models\OfflineModeSetting;
use App\Models\User;
use App\Services\PinLoginService;
use App\Support\EmailFailureMessage;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $turnstileEnabled = config('services.turnstile.enabled') && ! OfflineModeSetting::isOffline();
        $turnstileSiteKey = config('services.turnstile.site_key');

        if ($turnstileEnabled && empty($turnstileSiteKey)) {
            abort(500, 'Cloudflare Turnstile is not properly configured.');
        }

        return Inertia::render('Auth/Register', [
            'turnstile_enabled' => $turnstileEnabled,
            'turnstile_site_key' => $turnstileSiteKey,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, PinLoginService $pinLogin): RedirectResponse
    {
        $turnstileEnabled = config('services.turnstile.enabled') && ! OfflineModeSetting::isOffline();

        $request->validate([
            'first_name' => 'string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'cf-turnstile-response' => $turnstileEnabled ? 'required' : 'nullable',
        ]);

        if ($turnstileEnabled) {
            // Verify Turnstile response with Cloudflare
            $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $request->input('cf-turnstile-response'),
                'remoteip' => $request->ip(),
            ])->json();

            // If Turnstile verification fails
            if (! $response['success']) {
                throw ValidationException::withMessages([
                    'cf-turnstile-response' => ['Failed Turnstile verification. Please try again.'],
                ]);
            }
        }

        if (BannedEmail::isBanned($request->email)) {
            throw ValidationException::withMessages(['email' => 'Registration with this email is not allowed.']);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            // Not active until the verification email is both sent and
            // clicked (VerifyEmailController clears this) — or an admin
            // overrides it (UserAdminController::reactivate), for offline
            // mode or a broken mail provider self-verification can't route
            // around.
            'disabled_at' => now(),
        ]);

        // The verification email send is a real network call to a third
        // party (Resend) — a provider-side rejection (e.g. sandbox mode
        // refusing the recipient domain) must not turn a successful
        // account creation into a 500. Surface it to the user instead.
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            Log::error('Failed to send registration verification email', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            session()->flash('email_error', EmailFailureMessage::describe($e));
        }

        Auth::login($user);

        // Same reasoning as AuthenticatedSessionController::store() — a
        // real login (registration counts) earns device trust.
        $pinLogin->grantTrust($pinLogin->resolveDevice($request), $user->id);

        return redirect(route('dashboard', absolute: false));
    }
}
