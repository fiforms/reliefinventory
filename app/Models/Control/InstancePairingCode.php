<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A one-time code an instance administrator hands to a computer so it can pair as an InstanceTrust. Only its hash is stored. */
class InstancePairingCode extends Model
{
    protected $connection = 'control';

    protected $table = 'instance_pairing_codes';

    protected $guarded = [];

    protected $casts = ['permissions' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];

    /** Not used, not expired. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
