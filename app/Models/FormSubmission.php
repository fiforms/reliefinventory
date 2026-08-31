<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One filled-out response to a Form. Approval is a two-state pending ->
 * approved|denied move (mirrors the pending/approved/denied shape from the
 * Facility approval design — no "blocked", since a submission isn't an
 * ongoing entity), only meaningful when the form's requires_approval is
 * true; approval_status stays null for plain data-collection forms.
 */
class FormSubmission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_DENIED],
    ];

    protected $fillable = [
        'form_id',
        'approval_status',
        'reviewed_by_person_id',
        'reviewed_at',
        'submitted_by_person_id',
        'submitter_name',
        'submitter_email',
        'submitter_phone',
        'ip_address',
        'linked_person_id',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function answers()
    {
        return $this->hasMany(FormAnswer::class)->orderBy('id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(Person::class, 'submitted_by_person_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(Person::class, 'reviewed_by_person_id');
    }

    public function linkedPerson()
    {
        return $this->belongsTo(Person::class, 'linked_person_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(FormSubmissionStatusLog::class)->orderBy('created_at');
    }

    public function submitterDisplayName(): string
    {
        return $this->submittedBy?->full_name ?? $this->submitter_name ?? 'Unknown';
    }

    /**
     * Move to approved/denied, applying any accompanying column updates
     * (e.g. linked_person_id on approve) and appending an audit-log row, all
     * in one transaction. Mirrors DonationOffer::transitionTo().
     */
    public function transitionTo(string $toStatus, ?int $personId, array $columnUpdates = [], ?string $notes = null): void
    {
        if (! in_array($toStatus, self::TRANSITIONS[$this->approval_status] ?? [], true)) {
            throw new InvalidArgumentException("Cannot move a form submission from \"{$this->approval_status}\" to \"{$toStatus}\".");
        }

        DB::transaction(function () use ($toStatus, $personId, $columnUpdates, $notes) {
            $from = $this->approval_status;
            $this->approval_status = $toStatus;
            $this->reviewed_by_person_id = $personId;
            $this->reviewed_at = now();
            foreach ($columnUpdates as $column => $value) {
                $this->{$column} = $value;
            }
            $this->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => $toStatus,
                'changed_by_person_id' => $personId,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Mark a non-approval-flow submission (requires_approval = false) as
     * reviewed, or add a note without changing anything — same
     * same-status-note idea as FeedbackReportController's update().
     */
    public function markReviewed(?int $personId, ?string $notes = null): void
    {
        DB::transaction(function () use ($personId, $notes) {
            $this->reviewed_by_person_id = $personId;
            $this->reviewed_at = now();
            $this->save();

            $this->statusLogs()->create([
                'from_status' => $this->approval_status,
                'to_status' => $this->approval_status,
                'changed_by_person_id' => $personId,
                'notes' => $notes,
            ]);
        });
    }
}
