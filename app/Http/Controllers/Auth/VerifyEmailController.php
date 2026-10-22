<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark a user's email address as verified from the signed link in the
     * verification email.
     *
     * Deliberately works without a session: the link is usually opened in
     * a different browser (a phone's mail app) or after the registration
     * session is gone, and an unverified account can't log in to get one
     * (AuthenticatedSessionController refuses it) — requiring auth here
     * left new accounts stuck in a login loop. The signed URL plus the
     * email hash is the proof of ownership. It verifies only; it never logs
     * anyone in (mail scanners open these links too).
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        // Checked here rather than with the `signed` middleware so an old
        // link gets a way forward instead of a bare 403 page.
        if (! $request->hasValidSignature()) {
            return redirect()->route('login')->withErrors([
                'email' => 'That verification link has expired. Log in and we will email you a new one.',
            ]);
        }

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        // Someone else is logged in in this browser — don't act on their
        // behalf or switch them to a different account.
        if ($request->user() && $request->user()->getKey() !== $user->getKey()) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            // Verifying is the self-serve path to becoming active — mirrors
            // the admin override in UserAdminController::reactivate().
            if ($user->disabled_at) {
                $user->update(['disabled_at' => null]);
            }

            event(new Verified($user));
        }

        // Deliberately not ->intended() — a stray pre-login "intended" URL
        // (e.g. someone bookmarked /dashboard and got bounced through
        // login first) must not skip the track picker below.
        $nextRoute = $user->requested_track
            ? route('registration.pending', absolute: false)
            : route('registration.track', absolute: false);

        if ($request->user()) {
            return redirect($nextRoute);
        }

        // Not logged in: send them to log in, then on to the same next step.
        $request->session()->put('url.intended', $nextRoute);

        return redirect()->route('login')
            ->with('status', 'Your email address is verified. Log in to continue.');
    }
}
