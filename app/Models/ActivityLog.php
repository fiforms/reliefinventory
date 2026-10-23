<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One station action (who did what, when) — written only by App\Services\ActivityRecorder, never updated.
 * `station` is TrainingMode::STATIONS' number; `training_team` is a snapshot of the actor's (or team driver's)
 * team at the time, so a later team change doesn't rewrite a past class.
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected $fillable = ['person_id', 'driver_id', 'training_team', 'station', 'action', 'subject_type', 'subject_id'];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
