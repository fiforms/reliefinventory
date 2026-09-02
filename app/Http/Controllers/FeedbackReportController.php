<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Mail\FeedbackReportStatusUpdated;
use App\Mail\FeedbackReportSubmitted;
use App\Models\FeedbackReport;
use App\Models\FeedbackReportStatusLog;
use App\Services\FeedbackContentScanner;
use App\Services\GitVersionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * In-app bug/feature reports, submitted from anywhere in the app (any
 * logged-in user, gated general-access) and triaged from /setup/feedback
 * (gated manage-feedback). See FeedbackReport for the status lifecycle.
 */
class FeedbackReportController extends Controller
{
    public function __construct(
        private GitVersionService $gitVersion,
        private FeedbackContentScanner $contentScanner,
    ) {}

    public function index()
    {
        $reports = FeedbackReport::with(['person', 'statusLogs.person'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['records' => $reports]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bug', 'feature'])],
            'urgent' => ['nullable', 'boolean'],
            'message' => ['required', 'string', 'max:5000'],
            'page_url' => ['required', 'string', 'max:2048'],
            'page_title' => ['nullable', 'string', 'max:255'],
            'screenshot' => ['nullable', 'image', 'max:8192'],
        ]);

        $screenshotPath = null;
        if ($request->hasFile('screenshot')) {
            $screenshotPath = $request->file('screenshot')->store('feedback-screenshots', 'local');
        }

        $flaggedReason = $this->contentScanner->scan($data['message']);

        $report = FeedbackReport::create([
            'person_id' => Auth::id(),
            'type' => $data['type'],
            'urgent' => $data['urgent'] ?? false,
            'message' => $data['message'],
            'page_url' => $data['page_url'],
            'page_title' => $data['page_title'] ?? null,
            'user_agent' => Str::limit($request->userAgent() ?? '', 255, ''),
            'screenshot_path' => $screenshotPath,
            'commit_hash' => $this->gitVersion->currentCommit(),
            'flagged_for_review' => $flaggedReason !== null,
            'flagged_reason' => $flaggedReason,
        ]);

        $this->notifyDevelopers($report);

        return response()->json(['record' => $report], 201);
    }

    public function screenshot(FeedbackReport $feedbackReport)
    {
        abort_unless($feedbackReport->screenshot_path, 404);
        abort_unless(Storage::disk('local')->exists($feedbackReport->screenshot_path), 404);

        return Storage::disk('local')->response($feedbackReport->screenshot_path);
    }

    /**
     * Handles both real status transitions and "just leave a note" updates
     * (status left equal to the report's current status — see
     * FeedbackReports.vue's "Add note" action) through the same endpoint;
     * either way, a FeedbackReportStatusLog row is created and the reporter
     * is notified. `new` is only valid here as a same-status note (a report
     * can't be advanced back to New), never as a forward transition.
     */
    public function update(Request $request, FeedbackReport $feedbackReport)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'seen', 'in_development', 'resolved'])],
            'comment' => ['nullable', 'string', 'max:5000', Rule::requiredIf($request->input('status') === 'resolved')],
        ]);

        $isTransition = $data['status'] !== $feedbackReport->status;
        $commentFlagReason = $this->contentScanner->scan($data['comment'] ?? null);

        $log = DB::transaction(function () use ($feedbackReport, $data, $commentFlagReason) {
            $log = FeedbackReportStatusLog::create([
                'feedback_report_id' => $feedbackReport->id,
                'status' => $data['status'],
                'comment' => $data['comment'] ?? null,
                'person_id' => Auth::id(),
            ]);

            $update = ['status' => $data['status']];
            // A comment triggering the scanner flags the report too — never
            // un-flags one already flagged from its original message.
            if ($commentFlagReason !== null) {
                $update['flagged_for_review'] = true;
                $update['flagged_reason'] = $feedbackReport->flagged_reason
                    ? $feedbackReport->flagged_reason.'; '.$commentFlagReason
                    : $commentFlagReason;
            }
            $feedbackReport->update($update);

            return $log;
        });

        $this->notifyReporter($log, $isTransition);

        return response()->json(['record' => $feedbackReport->fresh(['person', 'statusLogs.person'])]);
    }

    private function notifyDevelopers(FeedbackReport $report): void
    {
        $recipients = config('feedback.notify_emails');
        if (empty($recipients)) {
            return;
        }

        try {
            Mail::to($recipients)->send(new FeedbackReportSubmitted($report));
        } catch (\Throwable $e) {
            Log::error('Failed to send feedback report notification: '.$e->getMessage());
        }
    }

    private function notifyReporter(FeedbackReportStatusLog $log, bool $isTransition): void
    {
        $email = $log->feedbackReport->person->email ?? null;
        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new FeedbackReportStatusUpdated($log, $isTransition));
        } catch (\Throwable $e) {
            Log::error('Failed to send feedback status update notification: '.$e->getMessage());
        }
    }
}
