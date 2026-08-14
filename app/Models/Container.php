<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic Container — everything hand-liftable (box, bin, bag) that doesn't
 * need a pallet jack or forklift to move. Containment is one-directional
 * and structural: a Container can sit on a Pallet, but a Pallet never sits
 * inside a Container. Pallet is the largest container in the warehouse.
 */
class Container extends Model
{
    use HasFactory;

    protected $fillable = [
        'container_type_id',
        'pallet_id',
        'location_id',
        'description',
    ];

    public function containerType()
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
