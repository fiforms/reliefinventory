<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1) — whether the first-run wizard has been completed.
 * See the create_setup_state_table migration and App\Services\FirstRunService.
 */
class SetupState extends Model
{
    protected $table = 'setup_state';

    protected $fillable = ['completed_at', 'demo_data_seeded_at'];

    protected $casts = [
        'completed_at' => 'datetime',
        'demo_data_seeded_at' => 'datetime',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }
}
