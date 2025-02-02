<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageType extends Model
{
    use HasFactory;

    protected $table = 'packagetypes';

    protected $fillable = [
        'type',
    ];

    /**
     * Relationship: A package type can be associated with multiple order lines.
     */
    public function orderLines()
    {
        return $this->hasMany(OrderLine::class, 'packagetype_id');
    }

    /**
     * Relationship: A package type can be associated with multiple items.
     */
    public function items()
    {
        return $this->hasMany(Item::class, 'packagetypes_id');
    }
}
