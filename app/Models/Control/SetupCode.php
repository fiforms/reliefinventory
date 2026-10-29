<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time setup code for one empty warehouse (MULTI_WAREHOUSE.md §7). Only a hash is stored; the code itself is
 * shown once, when it is issued. Use App\Services\Warehouses\SetupCodes rather than this model directly.
 */
class SetupCode extends Model
{
    protected $connection = 'control';

    protected $table = 'warehouse_setup_codes';

    protected $fillable = ['warehouse_id', 'code_hash', 'expires_at', 'created_by'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Case, spaces and the dash don't matter when typing a code. */
    public static function hash(string $code): string
    {
        return hash('sha256', preg_replace('/[^A-Z0-9]/', '', strtoupper($code)));
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
