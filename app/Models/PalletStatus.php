<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PalletStatus extends Model
{
    use HasFactory;

    protected $table = 'palletstatus';

    protected $fillable = [
        'pallet_id',
        'location_id',
        'status',
    ];

    /**
     * Define relationship with Pallet.
     */
    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }

    /**
     * Define relationship with Location.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
