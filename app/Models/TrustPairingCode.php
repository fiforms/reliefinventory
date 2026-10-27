<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-use, short-lived code an admin generates in the Trust Console and a client or peer submits with its
 * public key. Only a hash is stored (the code is shown to the admin once). `grants` are the inbound
 * permissions the resulting trust will start with.
 */
class TrustPairingCode extends Model
{
    protected $fillable = ['code_hash', 'kind', 'name_hint', 'grants', 'kiosk_location_id', 'created_by', 'expires_at', 'used_at', 'trust_id'];

    protected $casts = [
        'grants' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function scopeUsable($query)
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /** Codes are typed by hand: upper-case, no dashes or spaces. */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }
}
