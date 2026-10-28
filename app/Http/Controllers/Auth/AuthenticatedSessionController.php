<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WarehouseChooserController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginHistory;
use App\Services\PinLoginService;
use App\Services\TrainingMode;
use App\Services\TurnstileService;
use App\Support\EmailFailureMessage;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view — deferring to the PIN-unlock screen when
     * that feature is on AND this specific device is admin-approved for
     * it AND at least one person actually has a live PIN-unlock grant on
     * it, unless the caller explicitly wants the email form (the unlock
     * screen's own "Log in with email" link sets ?email=1 to avoid
     * redirecting straight back to itself). An approved-but-empty device
     * (never had a real login on it yet, or every grant expired) gains
     * nothing from a trip through Unlock — it would just render a blank
     * tile grid with no way to log in — so it goes straight to the email
     * form instead of paying for that extra hop, same reasoning as the
     * unapproved-device case this already handled.
     */
    public function create(Request $request, PinLoginService $pinLogin): Response|RedirectResponse
    {
        // Training Mode: an approved device goes to the tap-your-name screen (staff PIN/email links are on it).
        if (! $request->boolean('email') && TrainingMode::enabled()
            && TrainingMode::availableOn($pinLogin->currentDevice($request))) {
            return redirect()->route('training-login');
        }

        if ($pinLogin->settings()->enabled && ! $request->boolean('email')) {
            $device = $pinLogin->currentDevice($request);
            if ($device?->isApproved() && $pinLogin->peopleTrustedOnDevice($device)->isNotEmpty()) {
                return redirect()->route('unlock');
            }
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request') && ! app(TurnstileService::class)->misconfigured(),
            'status' => session('status'),
            'chooser' => WarehouseChooserController::forLogin($request),
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

        // Unverified: log them out and send a fresh verification link — a
        // correct password proves it's them, and without this an expired
        // or lost link leaves no way back in (the resend button sits behind
        // login). The message has to land on the login page itself: the
        // verification notice is behind `auth`, so redirecting there just
        // bounced back to login and lost the error.
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            Auth::logout();

            $message = 'You need to verify your email address before logging in.';
            $throttleKey = 'verification-resend:'.$user->getKey();

            if (! RateLimiter::tooManyAttempts($throttleKey, 1)) {
                RateLimiter::hit($throttleKey, 60);

                try {
                    $user->sendEmailVerificationNotification();
                    $message .= ' We just emailed you a new link — check your spam folder if it isn\'t in your inbox.';
                } catch (\Throwable $e) {
                    Log::error('Failed to resend verification email at login', [
                        'user_id' => $user->getKey(),
                        'exception' => $e,
                    ]);
                    $message .= ' We could not send a new link: '.EmailFailureMessage::describe($e);
                }
            } else {
                $message .= ' A new link was sent less than a minute ago — check your inbox and spam folder.';
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
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
        $request->session()->forget(TrainingMode::SESSION_KEY);

        LoginHistory::record($user->id, 'password', $request->ip(), $request->userAgent());

        // A real password login is what earns a device its PIN-unlock
        // trust for this person — see PinLoginService for the two-gate
        // reasoning (device approval + per-person grant).
        $device = $pinLogin->currentDevice($request);
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
