<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'flagged_for_review',
        'flagged_reason',
    ];

    protected $casts = [
        'urgent' => 'boolean',
        'flagged_for_review' => 'boolean',
        'status_changed_at' => 'datetime',
    ];

    /**
     * Mirrors Transaction::booted() — status_changed_at is stamped from one
     * shared place rather than per-controller, so it's always accurate.
     */
    protected static function booted(): void
    {
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
}
