<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $table = 'units';

    // The units table (2025_01_24_011120_create_units_table) has never had
    // created_at/updated_at columns — without this, Eloquent's default
    // timestamp handling makes every create/update a 500 ("Unknown column
    // 'updated_at'"), same underlying cause as feedback #43's PackageType
    // bug, just never hit before since /setup/units didn't exist yet.
    public $timestamps = false;

    protected $fillable = [
        'abbreviation',
        'name',
        'type',
    ];

    public function itemTypes()
    {
        return $this->hasMany(ItemType::class, 'unit_id');
    }
}
