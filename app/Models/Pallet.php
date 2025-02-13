<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pallet extends Model
{
    use HasFactory;

    protected $table = 'pallets';

    protected $fillable = [
        'datepacked',
        'last_location_id',
        'last_status',
    ];


    /**
     * Define relationship with PalletStatus.
     */
    public function statuses()
    {
        return $this->hasMany(PalletStatus::class);
    }
    
    public function laststatus()
    {
        // return only one PalletStatus::class that represents the most
        // recent status change.
        return $this->hasOne(PalletStatus::class)->latest('created_at');
    }
    
    public function lastLocation()
    {
        return $this->belongsTo(Location::class,'last_location_id');
    }
    
    public function items()
    {
        return $this->hasMany(OrderLine::class);
    }
}
