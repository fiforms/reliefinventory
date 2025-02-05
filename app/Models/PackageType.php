<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

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
