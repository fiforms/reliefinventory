<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\BannerDismissal;
use App\Models\BannerSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Configuration for the single site-wide banner slot (see BannerSetting).
 * Reading the active banner + this user's dismissal state happens via the
 * shared Inertia prop (BannerService), not a GET here — this controller is
 * only for admin edits and per-user dismissal.
 */
class BannerSettingController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['feedback', 'maintenance', 'message'])],
            'message' => ['nullable', 'string', 'max:1000', Rule::requiredIf(in_array($request->input('type'), ['maintenance', 'message']))],
        ]);

        $settings = BannerSetting::current();
        $settings->applyChange($data['type'] ?? null, $data['type'] === 'feedback' ? null : ($data['message'] ?? null));

        return response()->json(['record' => $settings->fresh()]);
    }

    public function dismiss(Request $request)
    {
        $data = $request->validate([
            'version' => ['required', 'integer'],
        ]);

        BannerDismissal::firstOrCreate(
            ['person_id' => Auth::id(), 'version' => $data['version']],
            ['dismissed_at' => now()]
        );

        return response()->json(['ok' => true]);
    }
}
