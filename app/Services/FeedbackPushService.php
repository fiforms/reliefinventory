<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\FeedbackAggregationSetting;
use App\Models\FeedbackReport;
use App\Models\OfflineModeSetting;
use App\Models\Trust;
use App\Services\Federation\FederationClient;
use App\Services\Federation\TrustService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pushes this ("remote") instance's own feedback reports to a central
 * instance's ingest endpoint (FeedbackIngestController::storeFromTrust on the
 * central side). Deliberately has no
 * background retry: a push is attempted once, right when a report is
 * created (see FeedbackReportController::store), or in a single explicit
 * batch via the "Send Unsent Reports" action. This is never a hard
 * dependency for an instance's own feedback flow to work — every call here
 * fails soft (logs and returns) rather than throwing, and a freshly-cloned
 * instance that hasn't chosen a paired central instance simply never
 * attempts anything. Reports travel signed, through the trust the admin
 * chose (see App\Services\Federation).
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
        $settings = FeedbackAggregationSetting::current();
        if ($settings->feedback_trust_id === null) {
            return ['ok' => false, 'message' => 'Choose the instance to send reports to, and save first.'];
        }

        return $this->testTrust($settings->feedbackTrust);
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

        return $this->sendViaTrust(FeedbackAggregationSetting::current()->feedbackTrust, $payload, $error);
    }

    /** Signed send to a trusted central instance; never throws, `$error` carries a message an admin can act on. */
    private function sendViaTrust(?Trust $trust, array $payload, ?string &$error): ?Response
    {
        if (! $trust?->isApproved()) {
            $error = 'The trust this instance sends feedback through is no longer approved. Pair again in the Trust Console.';

            return null;
        }

        try {
            $response = FederationClient::for($trust)->post('/feedback', $payload);
        } catch (\Throwable $e) {
            Log::warning('Feedback push to central failed: '.$e->getMessage());
            $error = 'Could not reach '.$trust->name.': '.$e->getMessage();

            return null;
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('Feedback push to central failed with status '.$response->status());
        $error = match ($response->status()) {
            401 => $trust->name.' no longer trusts this instance (revoked, or its key changed). Pair again in the Trust Console.',
            403 => $trust->name.' has not allowed this instance to send feedback. Ask its administrator to grant "Sending feedback".',
            400 => $trust->name.' says this server\'s clock is too far off. Check the date and time.',
            503 => $trust->name.' is down for maintenance (HTTP 503). Try again later.',
            default => $trust->name.' answered with HTTP '.$response->status().'.',
        };

        return null;
    }

    /** @return array{ok: bool, message: string} */
    private function testTrust(?Trust $trust): array
    {
        if (! $trust?->isApproved()) {
            return ['ok' => false, 'message' => 'That trust is not approved. Pair again in the Trust Console.'];
        }
        $granted = TrustService::refreshOutbound($trust);
        if ($granted === null) {
            return ['ok' => false, 'message' => 'Could not get an answer from '.$trust->name.'. Check its address, that it still trusts this instance, and this server\'s clock.'];
        }
        if (! in_array('feedback.send', $granted, true)) {
            return ['ok' => false, 'message' => 'Connected to '.$trust->name.', but it has not allowed this instance to send feedback. Ask its administrator to grant "Sending feedback".'];
        }

        return ['ok' => true, 'message' => 'Connected to '.$trust->name.' — it accepts feedback from this instance.'];
    }

    private function serialize(FeedbackReport $report): array
    {
        return [
            'uuid' => $report->uuid,
            'warehouse_id' => $report->warehouse_id,
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
}
