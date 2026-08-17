<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\SecurePin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service PIN for the shared-terminal quick-unlock feature — a
 * person sets/changes their own, the same way PasswordController works.
 * Requires the real current password to change, since a PIN is a weaker
 * credential and shouldn't be settable by someone who's only guessed or
 * inherited an already-open session.
 */
class PinController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'digits:5', 'confirmed', new SecurePin],
        ]);

        // pin_hash is deliberately not in Person::$fillable — set directly.
        $person = $request->user();
        $person->pin_hash = Hash::make($validated['pin']);
        $person->save();

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $person = $request->user();
        $person->pin_hash = null;
        $person->save();

        return back();
    }
}
