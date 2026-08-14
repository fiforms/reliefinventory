<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The top tier of the container hierarchy — the vehicle/trailer a donation
 * arrives on. Received the moment it's dropped off, even before unloading,
 * so it can't be forgotten sitting in a parking lot.
 */
class Truck extends Model
{
    use HasFactory;

    protected $table = 'trucks';

    protected $fillable = [
        'donor_person_id',
        'status',
        'trailer_number',
        'rough_pallet_estimate',
        'contents_summary',
        'manifest_weight_lbs',
    ];

    protected $casts = [
        'manifest_weight_lbs' => 'decimal:2',
    ];

    public function donor()
    {
        return $this->belongsTo(Person::class, 'donor_person_id');
    }

    public function pallets()
    {
        return $this->hasMany(Pallet::class);
    }
}
