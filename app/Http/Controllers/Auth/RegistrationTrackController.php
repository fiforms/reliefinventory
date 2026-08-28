<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationTrackController extends Controller
{
    /**
     * "What brings you here?" — asked once, right after email
     * verification, so a self-registered account can be routed to the
     * right reviewer. Purely a captured intent for now (see the migration
     * adding people.requested_track) — it doesn't grant any access itself.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ChooseTrack', [
            'requested_track' => $request->user()->requested_track,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'requested_track' => 'required|in:volunteer,donor,partner',
        ]);

        $request->user()->update(['requested_track' => $validated['requested_track']]);

        return redirect()->route('registration.pending');
    }
}
