<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A lightweight directory for drivers who bring donations/deliveries —
 * deliberately not a Person: drivers aren't staff, donors, or customers by
 * default and don't need permissions/roles. See the 2026_08_21 migration.
 */
class Driver extends Model
{
    protected $table = 'drivers';

    protected $fillable = [
        'name',
        'phone',
        'carrier',
        // Set when this driver is also the donor (a walk-up personal-vehicle
        // donation) — see Receiving.vue's "Use as Donor" action.
        'person_id',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}
