<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Mail;

use App\Models\FeedbackReportStatusLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FeedbackReportStatusUpdated extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $isTransition is false when this log entry is a note left while the
     * status stayed the same (FeedbackReports.vue's "Add note" action) —
     * the subject/body read differently ("a note was added") than an
     * actual status change ("is now Acknowledged").
     */
    public function __construct(public FeedbackReportStatusLog $log, public bool $isTransition = true) {}

    public function build()
    {
        $labels = [
            'new' => 'New',
            'seen' => 'Acknowledged',
            'in_development' => 'In Development',
            'resolved' => 'Resolved',
        ];

        $subject = $this->isTransition
            ? 'Update on your report: '.($labels[$this->log->status] ?? $this->log->status)
            : 'New comment on your report';

        return $this->subject($subject)
            ->view('emails.feedback-report-status-updated');
    }
}
