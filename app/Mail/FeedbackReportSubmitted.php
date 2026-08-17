<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Mail;

use App\Models\FeedbackReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class FeedbackReportSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FeedbackReport $report) {}

    public function build()
    {
        $label = $this->report->type === 'bug' ? 'Bug Report' : 'Feature Request';
        $page = $this->report->page_title ?: $this->report->page_url;
        $urgentPrefix = $this->report->urgent ? '[URGENT] ' : '';

        $mail = $this->subject("{$urgentPrefix}[{$label}] {$page}")
            ->view('emails.feedback-report-submitted');

        if ($this->report->screenshot_path && Storage::disk('local')->exists($this->report->screenshot_path)) {
            $mail->attachData(
                Storage::disk('local')->get($this->report->screenshot_path),
                'screenshot.png',
                ['mime' => 'image/png']
            );
        }

        return $mail;
    }
}
