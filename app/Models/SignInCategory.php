<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable expansion of the kiosk's "Other" sign-in category (state
 * representative, maintenance/repair, ...) — mirrors the
 * ItemType/PackageType/PersonCategory lookup-table idiom rather than a
 * hardcoded enum. A free-text catch-all still lives on volunteer_sign_ins
 * itself for whatever isn't in this list yet.
 *
 * Scoped per kiosk_location (see the 2026_08_26 migration): this list does
 * double duty as the registered-person "Other category" picker on the
 * confirm-in screen *and* the Guest quick-flow's type picker (Maintenance/
 * Repair, FEMA, State, ...) — different locations can offer a different
 * roster of guest types, always alongside a free-text "Other".
 */
class SignInCategory extends Model
{
    protected $table = 'sign_in_categories';

    protected $fillable = ['kiosk_location_id', 'name'];

    public function kioskLocation()
    {
        return $this->belongsTo(KioskLocation::class);
    }
}
