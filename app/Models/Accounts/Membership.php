<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's place in one warehouse: which person it is there. The only record of who belongs where
 * (MULTI_WAREHOUSE.md §6) — people.account_id is a cached copy, checked against this, never trusted alone.
 */
class Membership extends Model
{
    protected $connection = 'accounts';

    public const ACTIVE = 'active';

    protected $fillable = ['account_id', 'warehouse_id', 'person_id', 'status', 'is_training'];

    protected $casts = [
        'is_training' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
