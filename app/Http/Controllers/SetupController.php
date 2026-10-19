<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Services\FirstRunService;
use App\Services\PinLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * First-run setup wizard (/install). Only reachable while FirstRunService says
 * the instance needs setup; afterwards both routes just send people to /login.
 * If RI_SETUP_CODE is configured, the code must be supplied — the hardening
 * for an instance reachable from the internet before its owner gets to it.
 */
class SetupController extends Controller
{
    public function show(FirstRunService $firstRun): Response|RedirectResponse
    {
        if (! $firstRun->needsSetup()) {
            return redirect()->route('login');
        }

        return Inertia::render('Setup/FirstRun', [
            'codeRequired' => filled(config('app.setup_code')),
        ]);
    }

    public function store(Request $request, FirstRunService $firstRun, PinLoginService $pinLogin): RedirectResponse
    {
        if (! $firstRun->needsSetup()) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:people,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'example_data' => ['boolean'],
            'setup_code' => [filled(config('app.setup_code')) ? 'required' : 'nullable', 'string'],
        ]);

        if (filled(config('app.setup_code')) && ! hash_equals((string) config('app.setup_code'), (string) $request->input('setup_code'))) {
            throw ValidationException::withMessages(['setup_code' => 'That setup code is not correct.']);
        }

        try {
            $result = $firstRun->completeSetup($data, (bool) ($data['example_data'] ?? false));
        } catch (RuntimeException) {
            return redirect()->route('login');
        }

        Auth::login($result['user']);
        $request->session()->regenerate();
        // Same as a real login: this browser earns device trust.
        $pinLogin->grantTrust($pinLogin->resolveDevice($request), $result['user']->id);

        return redirect()->route('dashboard')->with(
            'status',
            $result['demo_failed']
                ? 'Setup complete, but the example data could not be loaded (see the server log). You can start entering real data.'
                : 'Setup complete. Welcome to Relief Inventory!'
        );
    }
}
