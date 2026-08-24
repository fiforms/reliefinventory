<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1) — see the 2026_08_24 migration for why this
 * shape rather than a generic key-value settings table.
 */
class KioskSetting extends Model
{
    protected $table = 'kiosk_settings';

    protected $fillable = [
        'welcome_message',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }
}
