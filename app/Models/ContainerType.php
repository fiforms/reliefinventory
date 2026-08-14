<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Extensible lookup for generic Container types (box, bin, bag, ...) — same
 * admin-editable-lookup pattern as ItemType/PackageType.
 */
class ContainerType extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function containers()
    {
        return $this->hasMany(Container::class);
    }
}
