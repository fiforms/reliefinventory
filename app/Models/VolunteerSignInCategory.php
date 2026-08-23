<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable expansion of the kiosk's "Other" sign-in category — see
 * the create-table migration doc comment. Mirrors PersonCategory.
 */
class VolunteerSignInCategory extends Model
{
    protected $fillable = ['name'];
}
