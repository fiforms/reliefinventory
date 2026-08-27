<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\PinLoginSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Global on/off + trust-mode configuration for the PIN unlock feature —
 * gated on admin-system, matching every other system-wide toggle (backup
 * schedule, updates). Which specific devices are allowed to use it once
 * enabled is a separate, narrower decision — see TrustedDeviceController.
 */
class PinLoginSettingsController extends Controller
{
    public function show()
    {
        return response()->json(['settings' => PinLoginSetting::current()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'trust_mode' => ['required', Rule::in(['time_of_day', 'session_duration', 'indefinite'])],
            'trust_time_of_day' => 'nullable|date_format:H:i',
            'trust_session_minutes' => 'nullable|integer|min:1|max:10080', // up to 7 days
            'require_badge_and_pin' => 'required|boolean',
            'badge_login_enabled' => 'required|boolean',
        ]);

        if ($data['trust_mode'] === 'time_of_day' && empty($data['trust_time_of_day'])) {
            return response()->json(['errors' => ['trust_time_of_day' => ['A time of day is required for this trust mode.']]], 422);
        }
        if ($data['trust_mode'] === 'session_duration' && empty($data['trust_session_minutes'])) {
            return response()->json(['errors' => ['trust_session_minutes' => ['A session duration is required for this trust mode.']]], 422);
        }
        if (! $data['badge_login_enabled']) {
            // Can't require a badge scan for a feature that isn't offered at all.
            $data['require_badge_and_pin'] = false;
        }

        $settings = PinLoginSetting::current();
        $settings->update($data);

        return response()->json(['settings' => $settings->fresh()]);
    }
}
