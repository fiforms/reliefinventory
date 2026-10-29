<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\FeedbackAggregationSetting;
use App\Models\Trust;
use App\Services\FeedbackPushService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-editable settings for centralized feedback aggregation (which instance role this is and, for a 'remote',
 * which paired instance it sends reports through) — gated manage-feedback, matching the /setup/feedback page this
 * lives on. See FeedbackAggregationSetting for the singleton-row pattern; pairing instances is the Trust Console's job.
 */
class FeedbackAggregationSettingsController extends Controller
{
    public function show()
    {
        $settings = FeedbackAggregationSetting::current();

        return response()->json([
            'settings' => [
                'instance_role' => $settings->instance_role,
                'feedback_trust_id' => $settings->feedback_trust_id,
            ],
            // Instances this one is paired with, for the "send reports to" choice.
            'trusts' => Trust::where('kind', 'instance')->where('status', 'approved')->with('grants')->orderBy('name')->get()
                ->map(fn (Trust $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'base_url' => $t->base_url,
                    'may_send_feedback' => $t->grants->where('direction', 'outbound')->where('permission', 'feedback.send')->isNotEmpty(),
                ])->values(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'instance_role' => ['required', Rule::in(['remote', 'central'])],
            'feedback_trust_id' => ['nullable', Rule::exists('trusts', 'id')->where('kind', 'instance')->where('status', 'approved')],
        ]);

        $settings = FeedbackAggregationSetting::current();
        $settings->instance_role = $data['instance_role'];
        $settings->feedback_trust_id = $data['feedback_trust_id'] ?? null;
        $settings->save();

        return $this->show();
    }

    /** Remote only: asks the chosen central instance what it lets this one do. */
    public function test(FeedbackPushService $pushService)
    {
        return response()->json($pushService->testConnection());
    }
}
