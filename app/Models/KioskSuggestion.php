<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Type-ahead suggestion values for the kiosk's Agency and Task
 * (title_function) free-text fields — see the 2026_08_26 migration. One
 * table covers both kinds (same shape: an admin-managed list of strings
 * feeding a <datalist>); global, not per-location, unlike SignInCategory's
 * guest types.
 */
class KioskSuggestion extends Model
{
    public const KIND_AGENCY = 'agency';

    public const KIND_TASK = 'task';

    protected $fillable = ['kind', 'value'];

    public function scopeAgency($query)
    {
        return $query->where('kind', self::KIND_AGENCY);
    }

    public function scopeTask($query)
    {
        return $query->where('kind', self::KIND_TASK);
    }
}
