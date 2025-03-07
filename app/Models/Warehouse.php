<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'city',
        'state',
        'zip',
        'county_id',
        'manager_id',
    ];

    /**
     * Get the county associated with the warehouse.
     */
    public function county()
    {
        return $this->belongsTo(County::class);
    }

    /**
     * Get the manager associated with the warehouse.
     */
    public function manager()
    {
        return $this->belongsTo(Person::class, 'manager_id');
    }
}
