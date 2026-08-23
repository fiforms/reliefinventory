<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DonationOfferStatusLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'donation_offer_status_log';

    protected $fillable = [
        'donation_offer_id',
        'from_status',
        'to_status',
        'changed_by_person_id',
        'contact_method',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function donationOffer()
    {
        return $this->belongsTo(DonationOffer::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(Person::class, 'changed_by_person_id');
    }
}
