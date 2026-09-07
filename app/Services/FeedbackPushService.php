<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\FeedbackAggregationSetting;
use App\Models\FeedbackReport;
use App\Models\OfflineModeSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes this ("remote") instance's own feedback reports to a central
 * instance's ingest endpoint (see docs/centralized-feedback-design-brief.md
 * and FeedbackIngestController on the central side). Deliberately has no
 * background retry: a push is attempted once, right when a report is
 * created (see FeedbackReportController::store), or in a single explicit
 * batch via the "Send Unsent Reports" action. This is never a hard
 * dependency for an instance's own feedback flow to work — every call here
 * fails soft (logs and returns) rather than throwing, and a freshly-cloned
 * instance with no central_ingest_url/shared_secret configured simply never
 * attempts anything.
 */
class FeedbackPushService
{
    /**
     * Whether *automatic* push (on report create) should run right now.
     * Respects Offline Mode — a deliberate choice not to spend a possibly
     * limited/expensive field connection without an admin's say.
     */
    public function autoPushEnabled(): bool
    {
        return $this->configured() && ! OfflineModeSetting::isOffline();
    }

    /**
     * Whether central push is configured at all, independent of Offline
     * Mode. "Send Unsent Reports" is the explicit "I have a connectivity
     * window right now" override and checks only this, not autoPushEnabled.
     */
    public function configured(): bool
    {
        return FeedbackAggregationSetting::current()->pushConfigured();
    }

    /**
     * Single-report auto-push attempt right after creation. Silently does
     * nothing if push isn't configured/enabled right now — this is not
     * "the" way a report gets to central, just a best-effort head start;
     * "Send Unsent Reports" always exists to send anything this misses.
     */
    public function pushOne(FeedbackReport $report): void
    {
        if (! $this->autoPushEnabled()) {
            return;
        }

        $this->push(collect([$report]));
    }

    /**
     * The only retry mechanism: sends every currently-unsubmitted local
     * report (instance_identifier null — created here, not ingested from
     * elsewhere) in one batch request. Bypasses Offline Mode by design (see
     * configured() above) but still does nothing if central isn't
     * configured at all.
     *
     * @return array{attempted: bool, total: int, sent: int}
     */
    public function pushUnsent(): array
    {
        if (! $this->configured()) {
            return ['attempted' => false, 'total' => 0, 'sent' => 0];
        }

        $reports = FeedbackReport::whereNull('instance_identifier')
            ->whereNull('submitted_at')
            ->get();

        if ($reports->isEmpty()) {
            return ['attempted' => true, 'total' => 0, 'sent' => 0];
        }

        $sent = $this->push($reports);

        return ['attempted' => true, 'total' => $reports->count(), 'sent' => $sent];
    }

    /**
     * Sends the given reports in one batch request and marks only the
     * uuids central actually acknowledges as submitted — a 200 response
     * alone is never treated as success, since a batch can partially fail
     * (see the design brief). Returns how many were acknowledged.
     */
    private function push(Collection $reports): int
    {
        $settings = FeedbackAggregationSetting::current();

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Feedback-Shared-Secret' => $settings->shared_secret])
                ->post($settings->central_ingest_url, [
                    'instance_identifier' => $this->instanceIdentifier(),
                    'reports' => $reports->map(fn (FeedbackReport $r) => $this->serialize($r))->all(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Feedback push to central failed: '.$e->getMessage());

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('Feedback push to central failed with status '.$response->status());

            return 0;
        }

        $received = collect($response->json('received', []))->filter()->values();
        if ($received->isEmpty()) {
            return 0;
        }

        FeedbackReport::whereIn('uuid', $received)->update(['submitted_at' => now()]);

        return $received->count();
    }

    private function serialize(FeedbackReport $report): array
    {
        return [
            'uuid' => $report->uuid,
            'type' => $report->type,
            'urgent' => $report->urgent,
            'message' => $report->message,
            'page_url' => $report->page_url,
            'page_title' => $report->page_title,
            'user_agent' => $report->user_agent,
            'commit_hash' => $report->commit_hash,
            'flagged_for_review' => $report->flagged_for_review,
            'flagged_reason' => $report->flagged_reason,
            'created_at' => $report->created_at?->toIso8601String(),
            'reporter_name' => $report->reporterFullName(),
            'reporter_email' => $report->reporterEmail(),
        ];
    }

    /**
     * Defaults to this instance's own APP_URL host — matches how page_url
     * already captures instance identity per CLAUDE.md's feedback section.
     * Override via the Instance identifier field on /setup/feedback's
     * aggregation settings when APP_URL isn't a stable/meaningful
     * identifier for this instance.
     */
    public function instanceIdentifier(): string
    {
        return FeedbackAggregationSetting::current()->resolvedIdentifier();
    }
}
