<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Building confirmed empty" — an occupancy reset, never touches any
 * volunteer_sign_ins row. See VolunteerSignIn::scopeOccupying() and the
 * create-table migration doc comment.
 */
class BuildingCloseout extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['closed_at', 'closed_by_person_id', 'notes'];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function closedBy()
    {
        return $this->belongsTo(Person::class, 'closed_by_person_id');
    }
}
