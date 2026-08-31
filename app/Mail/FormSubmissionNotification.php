<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Mail;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors FeedbackReportSubmitted, sent to a form's free-text notify_emails
 * list — addresses that may not have an app login at all, so this is
 * separate from the in-app FormSubmissionReceived bell alert.
 */
class FormSubmissionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FormSubmission $submission) {}

    public function build()
    {
        $this->submission->loadMissing(['form', 'answers']);

        return $this->subject("New submission: {$this->submission->form->name}")
            ->view('emails.form-submission-notification');
    }
}
