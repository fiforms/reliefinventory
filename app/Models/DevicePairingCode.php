<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Single-use, short-lived 6-digit code a staff member generates for a location and types on a new kiosk. */
class DevicePairingCode extends Model
{
    protected $fillable = ['code', 'kiosk_location_id', 'created_by', 'expires_at', 'used_at', 'managed_device_id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function scopeUsable($query)
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
