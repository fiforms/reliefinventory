<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\FeedbackAggregationSetting;
use App\Models\FeedbackReport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Central-side receiver for centralized feedback aggregation (see
 * docs/centralized-feedback-design-brief.md and FeedbackPushService on the
 * remote side). Gated by VerifyFeedbackIngestSecret — instance-to-instance,
 * not a logged-in person — never by `permission:` middleware.
 *
 * Ingested reports land in this instance's own `feedback_reports` table
 * (tagged with instance_identifier) so /setup/feedback's triage list is a
 * single unified view, not central data merged with a separate remote feed.
 */
class FeedbackIngestController extends Controller
{
    /**
     * Upserts idempotently on (instance_identifier, uuid) — a real DB unique
     * constraint (see the 2026_09_06 migration), so a retried push can't
     * double-insert even under a race with another request for the same
     * instance. Validates each report individually and skips (rather than
     * rejecting the whole batch for) one that's malformed, since the batch
     * this responds to could otherwise partially fail for reasons outside
     * the sending instance's control — the response's `received` list is
     * the sender's only signal of what actually landed.
     */
    public function store(Request $request)
    {
        // A remote's "Test Connection" (FeedbackPushService::testConnection):
        // reaching here means the address and shared secret are right.
        if ($request->boolean('ping')) {
            return response()->json(['ok' => true, 'instance_role' => FeedbackAggregationSetting::current()->instance_role]);
        }

        $data = $request->validate([
            'instance_identifier' => ['required', 'string', 'max:255'],
            'reports' => ['required', 'array', 'min:1'],
        ]);

        return $this->ingest($data['reports'], $data['instance_identifier'], null);
    }

    /**
     * Reports from a trusted instance (`trust-auth:feedback.send`). The source identity is the trust itself —
     * the body's own claim about who it is plays no part — and is shown as the trust's address host.
     */
    public function storeFromTrust(Request $request)
    {
        $trust = $request->attributes->get('trust');
        $data = $request->validate(['reports' => ['required', 'array', 'min:1']]);
        $identifier = parse_url((string) $trust->base_url, PHP_URL_HOST) ?: $trust->name;

        return $this->ingest($data['reports'], (string) $identifier, $trust->id);
    }

    private function ingest(array $reports, string $instanceIdentifier, ?int $trustId)
    {
        $received = [];

        DB::transaction(function () use ($reports, $instanceIdentifier, $trustId, &$received) {
            foreach ($reports as $incoming) {
                $validator = Validator::make($incoming, [
                    'uuid' => ['required', 'uuid'],
                    'type' => ['required', Rule::in(['bug', 'feature'])],
                    'message' => ['required', 'string'],
                    'page_url' => ['required', 'string'],
                    'page_title' => ['nullable', 'string'],
                    'urgent' => ['nullable', 'boolean'],
                    'user_agent' => ['nullable', 'string'],
                    'commit_hash' => ['nullable', 'string'],
                    'app_version' => ['nullable', 'string', 'max:64'],
                    'flagged_for_review' => ['nullable', 'boolean'],
                    'flagged_reason' => ['nullable', 'string'],
                    'created_at' => ['nullable', 'date'],
                    'reporter_name' => ['nullable', 'string'],
                    'reporter_email' => ['nullable', 'email'],
                ]);

                if ($validator->fails()) {
                    Log::warning('Rejected malformed feedback report from '.$instanceIdentifier.': '.$validator->errors());

                    continue;
                }

                $report = $this->upsert($instanceIdentifier, $validator->validated(), $trustId);
                $received[] = $report->uuid;
            }
        });

        return response()->json(['received' => $received]);
    }

    private function upsert(string $instanceIdentifier, array $incoming, ?int $trustId): FeedbackReport
    {
        $key = ['instance_identifier' => $instanceIdentifier, 'uuid' => $incoming['uuid']];
        $values = [
            'type' => $incoming['type'],
            'urgent' => $incoming['urgent'] ?? false,
            'message' => $incoming['message'],
            'page_url' => $incoming['page_url'],
            'page_title' => $incoming['page_title'] ?? null,
            'user_agent' => $incoming['user_agent'] ?? null,
            'commit_hash' => $incoming['commit_hash'] ?? null,
            'app_version' => $incoming['app_version'] ?? null,
            'flagged_for_review' => $incoming['flagged_for_review'] ?? false,
            'flagged_reason' => $incoming['flagged_reason'] ?? null,
            'reporter_name' => $incoming['reporter_name'] ?? null,
            'reporter_email' => $incoming['reporter_email'] ?? null,
            'trust_id' => $trustId,
        ];

        try {
            $report = FeedbackReport::updateOrCreate($key, $values);
        } catch (QueryException $e) {
            // Lost a race with a concurrent identical push — the unique
            // constraint caught it before we did. The row now exists;
            // fall through to a plain update instead of a duplicate insert.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $report = FeedbackReport::where($key)->firstOrFail();
            $report->update($values);
        }

        // created_at reflects when the report actually happened on the
        // remote instance, not when central happened to receive it —
        // Eloquent already stamped "now" on insert, so only override it
        // once, on the row's first arrival.
        if ($report->wasRecentlyCreated && ! empty($incoming['created_at'])) {
            $report->created_at = $incoming['created_at'];
            $report->saveQuietly();
        }

        return $report;
    }
}
