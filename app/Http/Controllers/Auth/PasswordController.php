<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AccountSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'password_change_required' => false,
        ])->save();
        // The new password ends every other session of this login (in every warehouse), not this one.
        AccountSession::remember($request->user()->account);

        return back();
    }
}
