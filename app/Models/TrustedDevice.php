<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrustedDevice extends Model
{
    protected $table = 'trusted_devices';

    protected $fillable = [
        'device_token',
        'label',
        'status',
        'user_agent',
        'requested_at',
        'approved_by',
        'approved_at',
        'last_seen_at',
        'kiosk_mode',
        'kiosk_mode_enabled_at',
        'kiosk_mode_enabled_by_person_id',
        'kiosk_location_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'kiosk_mode' => 'boolean',
        'kiosk_mode_enabled_at' => 'datetime',
    ];

    public function approver()
    {
        return $this->belongsTo(Person::class, 'approved_by');
    }

    public function kioskModeEnabledBy()
    {
        return $this->belongsTo(Person::class, 'kiosk_mode_enabled_by_person_id');
    }

    public function kioskLocation()
    {
        return $this->belongsTo(KioskLocation::class);
    }

    public function grants()
    {
        return $this->hasMany(DeviceTrustGrant::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isInKioskMode(): bool
    {
        return $this->kiosk_mode;
    }
}
