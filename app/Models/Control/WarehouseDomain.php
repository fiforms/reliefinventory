<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A hostname that reaches exactly one warehouse (MULTI_WAREHOUSE.md §3). */
class WarehouseDomain extends Model
{
    protected $connection = 'control';

    protected $fillable = ['hostname', 'warehouse_id', 'is_primary', 'skip_chooser'];

    protected $casts = [
        'is_primary' => 'boolean',
        'skip_chooser' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $domain) => $domain->hostname = strtolower(trim($domain->hostname)));
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
