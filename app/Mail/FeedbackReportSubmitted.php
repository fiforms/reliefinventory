<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Mail;

use App\Models\FeedbackReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FeedbackReportSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FeedbackReport $report) {}

    public function build()
    {
        $label = $this->report->type === 'bug' ? 'Bug Report' : 'Feature Request';
        $page = $this->report->page_title ?: $this->report->page_url;
        $urgentPrefix = $this->report->urgent ? '[URGENT] ' : '';
        // See FeedbackContentScanner — a flagged report may be a genuine
        // prompt-injection/exfiltration attempt aimed at whoever (human or
        // AI) reads this next. Surface that in the subject line itself, not
        // just the body, so it's visible before anyone opens the email.
        $flaggedPrefix = $this->report->flagged_for_review ? '[FLAGGED] ' : '';

        return $this->subject("{$flaggedPrefix}{$urgentPrefix}[{$label}] {$page}")
            ->view('emails.feedback-report-submitted');
    }
}
