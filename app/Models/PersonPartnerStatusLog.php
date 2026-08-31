<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonPartnerStatusLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'person_partner_status_log';

    protected $fillable = [
        'person_id',
        'from_status',
        'to_status',
        'changed_by_person_id',
        'form_submission_id',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(Person::class, 'changed_by_person_id');
    }

    public function formSubmission()
    {
        return $this->belongsTo(FormSubmission::class);
    }
}
