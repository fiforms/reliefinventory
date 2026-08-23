<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuildingRollCallConfirmation extends Model
{
    // confirmed_at is the meaningful timestamp here — no created_at/
    // updated_at columns on this table.
    public $timestamps = false;

    protected $fillable = ['building_roll_call_id', 'volunteer_sign_in_id', 'confirmed_by_person_id', 'confirmed_at'];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function buildingRollCall()
    {
        return $this->belongsTo(BuildingRollCall::class);
    }

    public function volunteerSignIn()
    {
        return $this->belongsTo(VolunteerSignIn::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(Person::class, 'confirmed_by_person_id');
    }
}
