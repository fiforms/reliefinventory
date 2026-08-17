<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackReportStatusLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'feedback_report_id',
        'status',
        'comment',
        'person_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function feedbackReport()
    {
        return $this->belongsTo(FeedbackReport::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}
