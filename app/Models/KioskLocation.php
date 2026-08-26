<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A physical volunteer-kiosk site — see the 2026_08_26 migration series.
 * `name` is the required header shown on the kiosk; `welcome_message` is a
 * separate, optional banner line shown only when non-blank. Which location
 * a given kiosk device belongs to lives on TrustedDevice::kiosk_location_id,
 * assigned when kiosk mode is enabled — not read from a single global
 * setting, so multiple physical kiosks can each show their own location.
 * Never deleted once referenced by devices/categories; deactivate via
 * `active` instead (same reasoning as Person::isSystem()).
 */
class KioskLocation extends Model
{
    protected $fillable = ['name', 'welcome_message', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function signInCategories()
    {
        return $this->hasMany(SignInCategory::class);
    }
}
