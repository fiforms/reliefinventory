<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pallet extends Model
{
    use HasFactory;

    protected $table = 'pallets';

    protected $fillable = [
        'datepacked',
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
    
    public function items()
    {
        return $this->hasMany(OrderLine::class);
    }
}
