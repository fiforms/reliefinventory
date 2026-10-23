<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BannedEmail;
use App\Models\User;
use App\Services\PinLoginService;
use App\Services\TurnstileService;
use App\Support\EmailFailureMessage;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
    public function create(TurnstileService $turnstile): Response
    {
        $turnstileEnabled = $turnstile->enabled();
        $turnstileSiteKey = $turnstile->siteKey();

        if ($turnstile->misconfigured()) {
            throw new HttpResponseException($turnstile->misconfiguredResponse());
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
    public function store(Request $request, PinLoginService $pinLogin, TurnstileService $turnstile): RedirectResponse
    {
        $turnstileEnabled = $turnstile->enabled();
        if ($turnstile->misconfigured()) {
            throw new HttpResponseException($turnstile->misconfiguredResponse());
        }

        $request->validate([
            'first_name' => 'string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'cf-turnstile-response' => $turnstileEnabled ? 'required' : 'nullable',
        ]);

        if ($turnstileEnabled) {
            $turnstile->verify($request->input('cf-turnstile-response'), $request->ip());
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
        $pinLogin->grantTrust($pinLogin->currentDevice($request), $user->id);

        return redirect(route('dashboard', absolute: false));
    }
}
