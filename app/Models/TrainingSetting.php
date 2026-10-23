<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Singleton (always id = 1), same pattern as PinLoginSetting — see App\Services\TrainingMode. */
class TrainingSetting extends Model
{
    protected $table = 'training_settings';

    protected $fillable = ['enabled', 'enabled_at', 'enabled_by', 'prepared_at', 'prepared_by', 'prepared_summary'];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'prepared_at' => 'datetime',
        'prepared_summary' => 'array',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }
}
