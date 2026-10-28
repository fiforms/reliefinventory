<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\TrainingLoginController;
use App\Models\Person;
use App\Models\TrainingRegistration;
use App\Services\TrainingMode;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Trainee self sign-up: the public /join page people open on their own phones during the project overview (from a
 * QR code on the Training Mode page). Open only in a training warehouse. It puts them on the training list (a
 * trainee Person with the Trainee role, as TrainingLoginController::createTrainee makes) and keeps the follow-up
 * answers in training_registrations. It never logs anyone in: station access stays on the approved tablets.
 *
 * Built for a room full of people on one Wi-Fi network, so all 50+ share an IP address: the route's rate limit is
 * 80 a minute per IP and Turnstile (TurnstileService, when enabled) is what keeps bots out. Signing up again with the same
 * name (a double tap, or coming back to add an email) updates that person rather than adding a second tile.
 */
class TraineeSignupController extends Controller
{
    public function __construct(private TurnstileService $turnstile) {}

    public function show()
    {
        $turnstileEnabled = $this->turnstile->enabled();
        $unavailable = $turnstileEnabled && $this->turnstile->misconfigured();

        return Inertia::render('Join', [
            'open' => TrainingMode::active(),
            'turnstile_enabled' => $turnstileEnabled && ! $unavailable,
            'turnstile_site_key' => $turnstileEnabled && ! $unavailable ? $this->turnstile->siteKey() : null,
            'turnstile_unavailable' => $unavailable,
        ]);
    }

    public function store(Request $request)
    {
        if (! TrainingMode::active()) {
            return response()->json(['message' => 'Sign-up is closed right now.'], 403);
        }
        $turnstileEnabled = $this->turnstile->enabled();
        if ($turnstileEnabled && $this->turnstile->misconfigured()) {
            return response()->json(['message' => 'Sign-up can\'t accept entries right now (spam protection isn\'t set up on this server).'], 503);
        }

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'wants_updates' => 'boolean',
            'help_volunteer' => 'boolean',
            'help_software' => 'boolean',
            'help_organization' => 'boolean',
            'help_other' => 'boolean',
            'recommend' => 'nullable|string|max:1000',
            'comments' => 'nullable|string|max:2000',
            'cf-turnstile-response' => $turnstileEnabled ? 'required' : 'nullable',
        ]);
        if ($turnstileEnabled) {
            $this->turnstile->verify($data['cf-turnstile-response'], $request->ip());
        }

        $first = trim($data['first_name']);
        $last = trim($data['last_name']);
        $answers = [
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'wants_updates' => (bool) ($data['wants_updates'] ?? false),
            'help_volunteer' => (bool) ($data['help_volunteer'] ?? false),
            'help_software' => (bool) ($data['help_software'] ?? false),
            'help_organization' => (bool) ($data['help_organization'] ?? false),
            'help_other' => (bool) ($data['help_other'] ?? false),
            'recommend' => $data['recommend'] ?? null,
            'comments' => $data['comments'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ];

        DB::transaction(function () use ($first, $last, $answers) {
            $person = Person::where('is_trainee', true)->where('first_name', $first)->where('last_name', $last)->lockForUpdate()->first()
                ?? TrainingLoginController::createTrainee($first, $last, null);
            if ($person->disabled_at) {
                $person->disabled_at = null; // signing up again puts a trainee back on the list
            }
            if ($answers['phone'] && ! $person->phone) {
                $person->phone = $answers['phone'];
            }
            $person->save();

            $registration = TrainingRegistration::where('person_id', $person->id)->latest('id')->first();
            // Keep what they said before when the second go leaves a field blank.
            $registration
                ? $registration->update(array_filter($answers, fn ($v) => $v !== null && $v !== false) + ['updated_at' => now()])
                : TrainingRegistration::create($answers + ['person_id' => $person->id]);
        });

        return response()->json(['first_name' => $first], 201);
    }
}
