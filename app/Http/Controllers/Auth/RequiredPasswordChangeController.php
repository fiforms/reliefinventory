<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers\Auth;

use App\Auth\AccountSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen EnsurePasswordChanged holds a session on when an administrator
 * created the account with a temporary password. No current-password field:
 * the person just logged in with it.
 */
class RequiredPasswordChangeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->password_change_required) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ChangePasswordRequired');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        if ($user->password && Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password different from the temporary one you were given.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_change_required' => false,
        ])->save();
        // The new password ends every other session of this login (in every warehouse), not this one.
        AccountSession::remember($request->user()->account);

        return redirect()->route('dashboard');
    }
}
