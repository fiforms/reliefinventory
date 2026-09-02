<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        // Deliberately not ->intended() — a stray pre-login "intended" URL
        // (e.g. someone bookmarked /dashboard and got bounced through
        // login first) must not skip the track picker below.
        $nextRoute = $request->user()->requested_track
            ? route('registration.pending', absolute: false)
            : route('registration.track', absolute: false);

        if ($request->user()->hasVerifiedEmail()) {
            return redirect($nextRoute);
        }

        if ($request->user()->markEmailAsVerified()) {
            // Verifying is the self-serve path to becoming active — mirrors
            // the admin override in UserAdminController::reactivate().
            if ($request->user()->disabled_at) {
                $request->user()->update(['disabled_at' => null]);
            }

            event(new Verified($request->user()));
        }

        return redirect($nextRoute);
    }
}
