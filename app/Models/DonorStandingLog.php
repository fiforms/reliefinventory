<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only history of a donor's standing: one row per change (who, when, why). */
class DonorStandingLog extends Model
{
    protected $table = 'donor_standing_log';

    protected $fillable = ['person_id', 'from_standing', 'to_standing', 'note', 'person_id_user'];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(Person::class, 'person_id_user');
    }
}
