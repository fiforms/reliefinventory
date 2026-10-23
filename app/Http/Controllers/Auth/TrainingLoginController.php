<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\PinLoginService;
use App\Services\TrainingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training Mode's tap-your-name login (see App\Services\TrainingMode). Offered only while training mode is on and
 * only on an approved TrustedDevice — the same approval PIN unlock needs — so a stranger's browser never sees the
 * name list. A trainee taps their name, confirms their team and is logged in with no password; the session is
 * marked (TrainingMode::SESSION_KEY) and HasPermissions caps it to TrainingMode::TRAINING_KEYS. "Add New" puts a
 * new trainee on the list from the device itself. Staff keep their own PIN unlock / email login beside it.
 *
 * A trainee is a Person with `is_trainee` and the Trainee role. Logging in needs a User row, and User only sees
 * people with an email, so a trainee gets a placeholder `trainee-<id>@training.invalid` (.invalid is reserved:
 * it can never receive mail) and no password, so it can never be used for an email login.
 */
class TrainingLoginController extends Controller
{
    public function show(Request $request, PinLoginService $pinLogin): Response|RedirectResponse
    {
        if (! TrainingMode::enabled()) {
            return redirect()->route('login', ['email' => 1]);
        }
        $device = $pinLogin->currentDevice($request);
        if (! TrainingMode::availableOn($device)) {
            return redirect()->route('login', ['email' => 1]);
        }

        $settings = $pinLogin->settings();

        return Inertia::render('Auth/TrainingLogin', [
            'trainees' => Person::where('is_trainee', true)->whereNull('disabled_at')
                ->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Person $p) => ['id' => $p->id, 'full_name' => $p->full_name, 'team' => $p->training_team])
                ->values(),
            'teams' => TrainingMode::TEAMS,
            'staffPinAvailable' => $settings->enabled && $pinLogin->peopleTrustedOnDevice($device)->isNotEmpty(),
        ]);
    }

    public function login(Request $request, PinLoginService $pinLogin)
    {
        $this->ensureAvailable($request, $pinLogin);
        // Not `exists:` — this is a guest endpoint, and a validation difference would let a caller probe ids.
        $data = $request->validate([
            'person_id' => 'required|integer',
            'team' => ['required', 'integer', Rule::in(TrainingMode::TEAMS)],
        ]);

        $person = Person::where('is_trainee', true)->whereNull('disabled_at')->find($data['person_id']);
        if (! $person) {
            return response()->json(['message' => 'That name isn\'t on the training list.'], 404);
        }

        $person->training_team = $data['team'];
        $person->save();

        return $this->startSession($request, $person);
    }

    public function addTrainee(Request $request, PinLoginService $pinLogin)
    {
        $this->ensureAvailable($request, $pinLogin);
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'team' => ['required', 'integer', Rule::in(TrainingMode::TEAMS)],
        ]);
        $first = trim($data['first_name']);
        $last = trim($data['last_name']);

        if (Person::where('is_trainee', true)->where('first_name', $first)->where('last_name', $last)->exists()) {
            return response()->json(['message' => "$first $last is already on the list — tap the name to log in."], 409);
        }

        $person = DB::transaction(fn () => self::createTrainee($first, $last, $data['team']));

        return $this->startSession($request, $person, 201);
    }

    /**
     * Leave a training login for a full one (the "needs a full login" page, or the menu): log out, then go to
     * PIN unlock or the email form. Logging out first matters — the login pages are guest-only.
     */
    public function exit(Request $request): RedirectResponse
    {
        $to = $request->input('to') === 'pin' ? route('unlock') : route('login', ['email' => 1]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($to);
    }

    /** A new trainee: also used by the Training setup page's trainee list. */
    public static function createTrainee(string $first, string $last, ?int $team): Person
    {
        $person = Person::create(['first_name' => $first, 'last_name' => $last, 'source_system' => 'training']);
        // Not mass-assignable on purpose — set here, server-side only.
        $person->is_trainee = true;
        $person->training_team = $team;
        $person->email = 'trainee-'.$person->id.'@training.invalid';
        $person->email_verified_at = now();
        $person->approved_at = now(); // EnsureAccountApproved: a trainee isn't a pending self-registration
        $person->save();

        if ($role = Role::where('name', TrainingMode::ROLE)->first()) {
            $person->roles()->syncWithoutDetaching([$role->id]);
        }

        return $person;
    }

    private function startSession(Request $request, Person $person, int $status = 200)
    {
        $user = User::find($person->id);
        if (! $user || $user->isLoginDisabled()) {
            return response()->json(['message' => 'This account can\'t log in.'], 403);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(TrainingMode::SESSION_KEY, $person->id);
        LoginHistory::record($person->id, 'training', $request->ip(), $request->userAgent());

        return response()->json(['redirect' => route('dashboard')], $status);
    }

    private function ensureAvailable(Request $request, PinLoginService $pinLogin): void
    {
        if (! TrainingMode::enabled() || ! TrainingMode::availableOn($pinLogin->currentDevice($request))) {
            abort(response()->json(['message' => 'Training login is not available on this device.'], 403));
        }
    }
}
