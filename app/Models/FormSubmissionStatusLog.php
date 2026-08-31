<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormSubmissionStatusLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'form_submission_status_log';

    protected $fillable = [
        'form_submission_id',
        'from_status',
        'to_status',
        'changed_by_person_id',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(Person::class, 'changed_by_person_id');
    }
}
