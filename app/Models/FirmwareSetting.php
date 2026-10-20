<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Singleton (always id=1): where this instance's R Stations look for firmware. See the migration. */
class FirmwareSetting extends Model
{
    protected $fillable = ['source_url'];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /** The other server devices are pointed at, or null when this instance serves its own releases. */
    public static function sourceUrl(): ?string
    {
        return self::current()->source_url ?: null;
    }
}
