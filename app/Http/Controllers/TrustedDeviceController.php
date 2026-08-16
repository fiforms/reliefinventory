<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Approve/revoke which specific devices may offer PIN unlock — the
 * hardening gate on top of the global on/off switch (PinLoginSettingsController).
 * Deliberately no delete: a revoked device stays in the list as a record
 * of what was once approved and by whom, matching this app's general
 * audit-trail-over-deletion convention (e.g. pallet status history).
 */
class TrustedDeviceController extends Controller
{
    public function index()
    {
        $devices = TrustedDevice::with('approver')
            ->withCount(['grants' => fn ($q) => $q->active()])
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'revoked')")
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json(['records' => $devices]);
    }

    public function approve(Request $request, $id)
    {
        $data = $request->validate(['label' => 'nullable|string|max:255']);

        $device = TrustedDevice::findOrFail($id);
        $device->update([
            'status' => 'approved',
            'label' => $data['label'] ?? $device->label,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return response()->json(['record' => $device->fresh('approver')]);
    }

    public function revoke($id)
    {
        $device = TrustedDevice::findOrFail($id);
        $device->update(['status' => 'revoked']);

        // Revoking a device should invalidate any trust already granted on
        // it — otherwise a device revoked for cause (lost/stolen) would
        // still show whoever was last trusted until their grant happened
        // to expire on its own.
        $device->grants()->delete();

        return response()->json(['record' => $device->fresh('approver')]);
    }

    public function relabel(Request $request, $id)
    {
        $data = $request->validate(['label' => 'nullable|string|max:255']);

        $device = TrustedDevice::findOrFail($id);
        $device->update(['label' => $data['label']]);

        return response()->json(['record' => $device->fresh('approver')]);
    }
}
