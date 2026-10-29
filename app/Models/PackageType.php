<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageType extends Model
{
    use HasFactory;

    protected $table = 'packagetypes';

    // The packagetypes table has never had created_at/updated_at columns
    // (see its create/2025_02_05 update migrations) — without this,
    // Eloquent's default timestamp handling makes any create() or save()
    // throw ("Unknown column 'updated_at'"), which is exactly what feedback
    // #43 ran into trying to add a package type on the way to adding an item.
    public $timestamps = false;

    protected $fillable = [
        'plural',
        'singular',
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
