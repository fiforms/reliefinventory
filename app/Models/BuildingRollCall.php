<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A fire-safety roll call: a frozen snapshot of who was occupying the
 * building when it started, worked independently by however many
 * people/gathering areas are doing headcount — no live sync between them,
 * "who's not accounted for" is computed on demand from the snapshot minus
 * everyone confirmed so far. See the create-table migration doc comment.
 */
class BuildingRollCall extends Model
{
    protected $fillable = ['started_at', 'started_by_person_id', 'closed_at', 'closed_by_person_id', 'notes'];

    protected $casts = [
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function startedBy()
    {
        return $this->belongsTo(Person::class, 'started_by_person_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(Person::class, 'closed_by_person_id');
    }

    /**
     * The frozen roster — VolunteerSignIn rows that were occupying the
     * building at the moment this roll call started.
     */
    public function snapshotSignIns()
    {
        return $this->belongsToMany(VolunteerSignIn::class, 'building_roll_call_snapshot')
            ->with('person');
    }

    public function confirmations()
    {
        return $this->hasMany(BuildingRollCallConfirmation::class)->orderBy('confirmed_at');
    }

    /**
     * Snapshot entries with no matching confirmation yet — the flagged
     * list a coordinator checks. Loaded eagerly by the caller
     * (snapshotSignIns.person, confirmations) rather than queried here, to
     * keep this a plain in-memory diff instead of a second round trip.
     */
    public function missingSignIns()
    {
        $confirmedIds = $this->confirmations->pluck('volunteer_sign_in_id')->all();

        return $this->snapshotSignIns->reject(fn ($signIn) => in_array($signIn->id, $confirmedIds, true));
    }
}
