<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Notifications;

use App\Models\FormSubmission;
use Illuminate\Notifications\Notification;

/**
 * In-app bell alert for a form's notify_person_ids — mirrors
 * KioskCheckInAlert (database-only for now, additive to add a channel
 * later). Separate from the notify_emails mail below, which goes to
 * addresses that may not have a login at all.
 */
class FormSubmissionReceived extends Notification
{
    public function __construct(private readonly FormSubmission $submission) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        $this->submission->loadMissing('form');

        return [
            'kind' => 'form_submission',
            'form_submission_id' => $this->submission->id,
            'form_id' => $this->submission->form_id,
            'form_name' => $this->submission->form->name,
            'submitter_name' => $this->submission->submitterDisplayName(),
            'submitted_at' => $this->submission->created_at?->toIso8601String(),
        ];
    }
}
