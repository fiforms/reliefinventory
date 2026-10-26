<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FeedbackReport extends Model
{
    protected $fillable = [
        'person_id',
        'type',
        'urgent',
        'status',
        'message',
        'page_url',
        'page_title',
        'user_agent',
        'screenshot_path',
        'commit_hash',
        'app_version',
        'flagged_for_review',
        'flagged_reason',
        'uuid',
        'instance_identifier',
        'reporter_name',
        'reporter_email',
        'submitted_at',
        'trust_id',
    ];

    protected $casts = [
        'urgent' => 'boolean',
        'flagged_for_review' => 'boolean',
        'status_changed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    /**
     * Mirrors Transaction::booted() — status_changed_at is stamped from one
     * shared place rather than per-controller, so it's always accurate.
     */
    protected static function booted(): void
    {
        static::creating(function (FeedbackReport $report) {
            // Origin-stamped, globally-unique id used as the idempotency key
            // for centralized aggregation (see the 2026_09_06 migration).
            // Central's ingest sets this explicitly from the remote's own
            // uuid instead — never generate over one already provided.
            $report->uuid ??= (string) Str::uuid();
        });

        static::saving(function (FeedbackReport $report) {
            if ($report->isDirty('status')) {
                $report->status_changed_at = now();
            }
        });
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(FeedbackReportStatusLog::class)->orderBy('created_at');
    }

    /**
     * A locally-created report always has a real Person; an ingested one
     * (instance_identifier set) never does and falls back to the identity
     * denormalized at ingest time — see FeedbackIngestController.
     */
    public function reporterFullName(): ?string
    {
        return $this->person?->full_name ?: $this->reporter_name;
    }

    public function reporterEmail(): ?string
    {
        return $this->person?->email ?: $this->reporter_email;
    }
}
