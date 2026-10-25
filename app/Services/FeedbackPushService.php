<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\FeedbackAggregationSetting;
use App\Models\FeedbackReport;
use App\Models\OfflineModeSetting;
use Illuminate\Http\Client\Response;
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
     * @return array{attempted: bool, total: int, sent: int, error: ?string}
     */
    public function pushUnsent(): array
    {
        if (! $this->configured()) {
            return ['attempted' => false, 'total' => 0, 'sent' => 0, 'error' => null];
        }

        $reports = FeedbackReport::whereNull('instance_identifier')
            ->whereNull('submitted_at')
            ->get();

        if ($reports->isEmpty()) {
            return ['attempted' => true, 'total' => 0, 'sent' => 0, 'error' => null];
        }

        $result = $this->push($reports);

        return ['attempted' => true, 'total' => $reports->count(), ...$result];
    }

    /**
     * Checks the saved central address and secret without sending any
     * report: posts a `ping` that central answers once the secret matches.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Enter the central instance\'s address and shared secret (or paste a connection code) and save first.'];
        }

        $settings = FeedbackAggregationSetting::current();
        $response = $this->send(['instance_identifier' => $this->instanceIdentifier(), 'ping' => true], $error);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }
        if ($response->json('ok') !== true) {
            return ['ok' => false, 'message' => $settings->ingestUrl().' answered, but not as a Relief Inventory central instance. Check the address, or update the central instance.'];
        }
        if ($response->json('instance_role') !== 'central') {
            return ['ok' => false, 'message' => 'Connected, but that instance is set to Remote, not Central. Change its role to Central so reports sent there are triaged.'];
        }

        return ['ok' => true, 'message' => 'Connected to '.parse_url($settings->ingestUrl(), PHP_URL_HOST).' — the shared secret matches.'];
    }

    /**
     * Sends the given reports in one batch request and marks only the
     * uuids central actually acknowledges as submitted — a 200 response
     * alone is never treated as success, since a batch can partially fail
     * (see the design brief).
     *
     * @return array{sent: int, error: ?string} how many were acknowledged, and why none were if the request failed
     */
    private function push(Collection $reports): array
    {
        $response = $this->send([
            'instance_identifier' => $this->instanceIdentifier(),
            'reports' => $reports->map(fn (FeedbackReport $r) => $this->serialize($r))->all(),
        ], $error);
        if ($error !== null) {
            return ['sent' => 0, 'error' => $error];
        }

        $received = collect($response->json('received', []))->filter()->values();
        if ($received->isEmpty()) {
            return ['sent' => 0, 'error' => 'Central answered but accepted none of the reports (see its log).'];
        }

        FeedbackReport::whereIn('uuid', $received)->update(['submitted_at' => now()]);

        return ['sent' => $received->count(), 'error' => null];
    }

    /**
     * Posts to central. Never throws: on failure $error gets a message an
     * admin can act on (it is also logged) and null is returned.
     */
    private function send(array $payload, ?string &$error): ?Response
    {
        $error = null;
        $settings = FeedbackAggregationSetting::current();
        $url = $settings->ingestUrl();

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Feedback-Shared-Secret' => $settings->shared_secret])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('Feedback push to central failed: '.$e->getMessage());
            $error = 'Could not reach '.parse_url($url, PHP_URL_HOST).': '.$e->getMessage();

            return null;
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('Feedback push to central failed with status '.$response->status());
        $error = match ($response->status()) {
            403 => 'Central refused the shared secret. Both instances need the same one — copy a fresh connection code from the central instance.',
            404, 405 => $url.' isn\'t a Relief Inventory central instance (HTTP '.$response->status().'). Check the address.',
            503 => 'Central is down for maintenance (HTTP 503). Try again later.',
            default => 'Central answered with HTTP '.$response->status().'.',
        };

        return null;
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
            'app_version' => $report->app_version,
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
