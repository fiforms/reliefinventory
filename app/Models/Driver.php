<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Models\Concerns\HasPinLogin;
use Illuminate\Database\Eloquent\Model;

/**
 * A lightweight directory for drivers who bring donations/deliveries and
 * carry outbound loads — deliberately not a Person: drivers aren't staff,
 * donors, or partners by default and don't need permissions/roles. See the
 * 2026_08_21 migration. pin_hash (2026_08_27) lets a driver log into the
 * Driver Portal with just phone + PIN to upload a signed BOL for their own
 * assigned loads — reuses HasPinLogin, the same hashing/verification logic
 * Person already uses for PIN-unlock.
 */
class Driver extends Model
{
    protected $table = 'drivers';

    use HasPinLogin;

    protected $fillable = [
        'name',
        'phone',
        'carrier',
        // Set when this driver is also the donor (a walk-up personal-vehicle
        // donation) — see Receiving.vue's "Use as Donor" action.
        'person_id',
    ];

    // Never mass-assignable — set directly by DriverController::setPin(),
    // same reasoning as Person::$hidden's pin_hash entry.
    protected $hidden = [
        'pin_hash',
    ];

    // Lets the Shipping page show whether a driver can log into the Driver
    // Portal yet, without ever serializing the hash itself.
    protected $appends = ['has_pin'];

    public function getHasPinAttribute(): bool
    {
        return $this->hasPin();
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}
