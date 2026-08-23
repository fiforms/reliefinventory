<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only "who changed what, when" trail for a VolunteerSignIn row —
 * see the create-table migration doc comment. Written only through
 * VolunteerSignIn::applyChanges(), never directly.
 */
class VolunteerSignInAuditLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'volunteer_sign_in_audit_log';

    protected $fillable = ['field', 'old_value', 'new_value', 'changed_by_person_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function volunteerSignIn()
    {
        return $this->belongsTo(VolunteerSignIn::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(Person::class, 'changed_by_person_id');
    }
}
