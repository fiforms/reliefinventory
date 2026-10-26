<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\FeedbackAggregationSetting;
use App\Models\Trust;
use App\Services\FeedbackPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                'central_ingest_url' => $settings->ingestUrl(),
                // Never sent to the client — only whether one is set, so
                // the UI can show "configured" without exposing the value.
                'shared_secret_set' => filled($settings->shared_secret),
                'feedback_trust_id' => $settings->feedback_trust_id,
                // The shared-secret way is kept for one release; false once an operator has turned it off.
                'legacy_enabled' => (bool) config('federation.legacy_feedback_secret'),
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

    /**
     * A pasted connection code (copied from the central instance) sets this
     * instance up as a remote in one step and wins over the other fields.
     * The central address may be given as a bare domain; it is normalized
     * to the full ingest URL (FeedbackAggregationSetting::normalizeCentralUrl).
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'instance_role' => ['required_without:connection_code', Rule::in(['remote', 'central'])],
            'instance_identifier' => 'nullable|string|max:255',
            'central_ingest_url' => 'nullable|string|max:2048',
            // Blank/omitted leaves the existing secret untouched — this is
            // the only field never round-tripped back to the client, so
            // there's nothing to "leave unchanged" by re-submitting it.
            'shared_secret' => 'nullable|string|max:255',
            'connection_code' => 'nullable|string|max:4096',
            'feedback_trust_id' => ['nullable', Rule::exists('trusts', 'id')->where('kind', 'instance')->where('status', 'approved')],
        ]);

        $settings = FeedbackAggregationSetting::current();
        $settings->instance_identifier = $data['instance_identifier'] ?? null;
        if (array_key_exists('feedback_trust_id', $data)) {
            $settings->feedback_trust_id = $data['feedback_trust_id'];
        }

        if (filled($data['connection_code'] ?? null)) {
            $parsed = FeedbackAggregationSetting::parseConnectionCode($data['connection_code']);
            if ($parsed === null) {
                throw ValidationException::withMessages([
                    'connection_code' => 'That isn\'t a valid connection code. Copy it again from the central instance\'s Feedback page.',
                ]);
            }
            $settings->instance_role = 'remote';
            $settings->central_ingest_url = $parsed['url'];
            $settings->shared_secret = $parsed['secret'];
            $settings->save();

            return $this->show();
        }

        $url = null;
        if (filled($data['central_ingest_url'] ?? null)) {
            $url = FeedbackAggregationSetting::normalizeCentralUrl($data['central_ingest_url']);
            if ($url === null) {
                throw ValidationException::withMessages([
                    'central_ingest_url' => 'Enter the central instance\'s address, like demo.reliefinventory.app.',
                ]);
            }
        }

        $settings->instance_role = $data['instance_role'];
        // Choosing a trust makes the address field moot; it stays untouched so the old setup can be returned to.
        $settings->central_ingest_url = $url;
        if (filled($data['shared_secret'] ?? null)) {
            $settings->shared_secret = $data['shared_secret'];
        }
        $settings->save();

        return $this->show();
    }

    /**
     * Central only: the code a remote instance pastes to connect here — this
     * instance's ingest URL plus its shared secret. Creates a secret if
     * there is none yet; `new_secret` replaces it, which disconnects every
     * remote using the old one until they paste the new code.
     */
    public function connectionCode(Request $request)
    {
        $request->validate(['new_secret' => 'nullable|boolean']);
        if (! config('federation.legacy_feedback_secret')) {
            return response()->json(['message' => 'Connection codes are retired. Connect remote instances from the Trust Console.'], 410);
        }

        $settings = FeedbackAggregationSetting::current();
        if ($settings->instance_role !== 'central') {
            return response()->json(['message' => 'Only a Central instance has a connection code. Set the role to Central and save first.'], 409);
        }

        $url = FeedbackAggregationSetting::normalizeCentralUrl(config('app.url'));
        if ($url === null) {
            return response()->json(['message' => 'This instance\'s own address (APP_URL) isn\'t set, so a code can\'t be made.'], 409);
        }

        if (blank($settings->shared_secret) || $request->boolean('new_secret')) {
            $settings->shared_secret = Str::random(40);
            $settings->save();
        }

        return response()->json([
            'code' => FeedbackAggregationSetting::connectionCode($url, $settings->shared_secret),
            'url' => $url,
            'settings' => $this->show()->getData(true)['settings'],
        ]);
    }

    /** Remote only: checks the saved address and secret against central. */
    public function test(FeedbackPushService $pushService)
    {
        return response()->json($pushService->testConnection());
    }
}
