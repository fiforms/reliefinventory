<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\FeedbackAggregationSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-editable settings for centralized feedback aggregation (which
 * instance role this is, where a 'remote' instance pushes to, and the
 * shared secret both sides of a pair must agree on) — gated manage-feedback,
 * matching the /setup/feedback page this lives on. See
 * FeedbackAggregationSetting for the singleton-row pattern.
 */
class FeedbackAggregationSettingsController extends Controller
{
    public function show()
    {
        $settings = FeedbackAggregationSetting::current();

        return response()->json([
            'settings' => [
                'instance_role' => $settings->instance_role,
                'instance_identifier' => $settings->instance_identifier,
                'central_ingest_url' => $settings->central_ingest_url,
                // Never sent to the client — only whether one is set, so
                // the UI can show "configured" without exposing the value.
                'shared_secret_set' => filled($settings->shared_secret),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'instance_role' => ['required', Rule::in(['remote', 'central'])],
            'instance_identifier' => 'nullable|string|max:255',
            'central_ingest_url' => 'nullable|url|max:2048',
            // Blank/omitted leaves the existing secret untouched — this is
            // the only field never round-tripped back to the client, so
            // there's nothing to "leave unchanged" by re-submitting it.
            'shared_secret' => 'nullable|string|max:255',
        ]);

        $settings = FeedbackAggregationSetting::current();

        $settings->instance_role = $data['instance_role'];
        $settings->instance_identifier = $data['instance_identifier'] ?? null;
        $settings->central_ingest_url = $data['central_ingest_url'] ?? null;
        if (filled($data['shared_secret'] ?? null)) {
            $settings->shared_secret = $data['shared_secret'];
        }
        $settings->save();

        return $this->show();
    }
}
