<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginHistory;
use App\Services\PinLoginService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view — deferring to the PIN-unlock screen when
     * that feature is on, unless the caller explicitly wants the email
     * form (the unlock screen's own "Log in with email" link sets
     * ?email=1 to avoid redirecting straight back to itself).
     */
    public function create(Request $request, PinLoginService $pinLogin): Response|RedirectResponse
    {
        if ($pinLogin->settings()->enabled && ! $request->boolean('email')) {
            return redirect()->route('unlock');
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, PinLoginService $pinLogin): RedirectResponse
    {
        $request->authenticate();

        // Get the authenticated user
        $user = $request->user();
        // print_r($user); die();

        // If the user implements MustVerifyEmail and has not verified their email,
        // log them out and redirect to a verification notice page with an error.
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            Auth::logout();

            return redirect()->route('verification.notice')
                ->withErrors(['email' => 'You need to verify your email address before logging in.']);
        }

        // Admin-deactivated account (User Administration page) — blocked
        // after Auth::attempt() succeeds, same shape as the MustVerifyEmail
        // check above, since Auth::attempt() itself has no concept of this.
        if ($user->isLoginDisabled()) {
            Auth::logout();

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated.']);
        }

        $request->session()->regenerate();

        LoginHistory::record($user->id, 'password', $request->ip(), $request->userAgent());

        // A real password login is what earns a device its PIN-unlock
        // trust for this person — see PinLoginService for the two-gate
        // reasoning (device approval + per-person grant).
        $device = $pinLogin->resolveDevice($request);
        $pinLogin->grantTrust($device, $user->id);
        $wasKiosk = $pinLogin->clearKioskMode($device);

        // A device coming out of kiosk lock lands back on the kiosk page
        // itself (with "Confirm Building Empty" offered there, not forced)
        // rather than the dashboard — see volunteer-kiosk-phone-design memory.
        if ($wasKiosk) {
            return redirect('/volunteers/kiosk?closeout=1');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * "Switch User" on a shared terminal: end the current session, same as
     * a real logout, but land back on the login route rather than '/' —
     * create() above already sends that straight to the PIN-unlock tiles
     * when the feature is enabled, so this doesn't need its own
     * eligibility check duplicated here. The device's trust grants are
     * untouched, so whoever's tapped next just needs their PIN.
     */
    public function switchUser(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
