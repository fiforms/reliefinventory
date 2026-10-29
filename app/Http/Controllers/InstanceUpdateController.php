<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\Control\InstanceUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The host-side updater's two calls (see InstanceUpdate). Guarded by InstanceUpdateSecret, not by a login. */
class InstanceUpdateController extends Controller
{
    /** The poll: what is waiting. `action` is only ever set while the request is unclaimed, so a restarted updater cannot re-run a half-done one. */
    public function poll(): JsonResponse
    {
        $row = InstanceUpdate::current();

        return response()->json([
            'action' => $row->state === 'requested' && ! $row->stale() ? $row->action : null,
            'state' => $row->state,
            'requested_at' => $row->requested_at?->toIso8601String(),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => 'required|in:'.implode(',', InstanceUpdate::STATES),
            'message' => 'nullable|string|max:1000',
            'current_version' => 'nullable|string|max:60',
            'available_version' => 'nullable|string|max:60',
        ]);

        $accepted = InstanceUpdate::current()->report($data['state'], $data['message'] ?? null, $data['current_version'] ?? null, $data['available_version'] ?? null);

        return response()->json(['accepted' => $accepted]);
    }
}
