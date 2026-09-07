<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gates the centralized-feedback ingest endpoint (FeedbackIngestController)
 * on a shared secret instead of `permission:` — this is instance-to-
 * instance traffic, not a logged-in person, so there's no Person to check a
 * permission against. Both instances in a remote/central pair must be
 * configured with the same feedback.shared_secret; if this instance has
 * none configured, ingest is refused outright (a central instance that
 * never set one hasn't opted into receiving pushes from anywhere).
 */
class VerifyFeedbackIngestSecret
{
    public function handle(Request $request, Closure $next)
    {
        $configured = (string) config('feedback.shared_secret');
        $provided = (string) $request->header('X-Feedback-Shared-Secret');

        if ($configured === '' || $provided === '' || ! hash_equals($configured, $provided)) {
            abort(403, 'Invalid or missing feedback shared secret.');
        }

        return $next($request);
    }
}
