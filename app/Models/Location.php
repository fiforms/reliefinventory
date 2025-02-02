<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $table = 'locations';

    protected $fillable = [
        'code',
        'status',
    ];

    /**
     * Define the relationship with PalletStatus.
     */
    public function palletStatuses()
    {
        return $this->hasMany(PalletStatus::class);
    }
}
