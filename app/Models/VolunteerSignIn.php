<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The facility sign-in kiosk record — see the create-table migration doc
 * comment for the FEMA-documentation reasoning behind the field set.
 */
class VolunteerSignIn extends Model
{
    public const CATEGORY_VOLUNTEER = 'volunteer';

    public const CATEGORY_OTHER = 'other';

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'person_id',
        'category',
        'other_category_id',
        'other_category_text',
        'agency',
        'title_function',
        'work_site',
        'description_of_work',
        'expected_departure_at',
        'signed_in_at',
        'signed_out_at',
        'status',
        'certified_at',
        'certified_by_person_id',
    ];

    protected $casts = [
        'expected_departure_at' => 'datetime',
        'signed_in_at' => 'datetime',
        'signed_out_at' => 'datetime',
        'certified_at' => 'datetime',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function otherCategory()
    {
        return $this->belongsTo(SignInCategory::class, 'other_category_id');
    }

    public function certifiedBy()
    {
        return $this->belongsTo(Person::class, 'certified_by_person_id');
    }

    public function auditLog()
    {
        return $this->hasMany(VolunteerSignInAuditLog::class)->orderBy('created_at');
    }

    /**
     * "Currently in the building" — computed, never stored. Open (or
     * pending_confirmation, since it's ambiguous, not confirmed departed)
     * AND signed in after the most recent building_closeouts.closed_at.
     * That second condition is the whole fix for occupancy drift: a
     * closeout instantly stops every stale open sign-in from counting,
     * without writing to a single one of those rows — the hours record
     * (status/signed_out_at) stays untouched and correctable later. See
     * the building-safety migration doc comment.
     */
    public function scopeOccupying($query)
    {
        $lastCloseoutAt = BuildingCloseout::max('closed_at');

        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PENDING_CONFIRMATION])
            ->when($lastCloseoutAt, fn ($q) => $q->where('signed_in_at', '>', $lastCloseoutAt));
    }

    /**
     * True once this row has sat open past the day it was signed in on —
     * the "forgotten sign-out" case. Computed on read rather than a stored/
     * job-flipped flag: unlike people.volunteer_active (which many
     * unrelated readers — the kiosk grid, reports — need to agree on
     * consistently even if nobody happens to look at a given moment), this
     * only matters at the moment someone looks at *this specific* record
     * (an admin's worklist, or the volunteer's own next sign-in), so
     * there's nothing a background job would add.
     */
    public function getIsStaleOpenAttribute(): bool
    {
        return $this->status === self::STATUS_OPEN
            && ! $this->signed_in_at->isToday();
    }

    /**
     * Apply a set of field updates as one audited edit — the only place
     * this model's columns are written after creation. Diffs against
     * current values so an audit row is written only for fields that
     * actually changed, all in one transaction alongside the save itself.
     */
    public function applyChanges(array $data, ?int $personId): void
    {
        DB::transaction(function () use ($data, $personId) {
            $stringify = fn ($v) => $v instanceof \DateTimeInterface ? $v->toDateTimeString() : $v;

            foreach ($data as $field => $value) {
                $old = $stringify($this->{$field});
                $this->{$field} = $value;
                $new = $stringify($this->{$field}); // re-read through the cast, so comparisons match what's stored

                if ($old !== $new) {
                    $this->auditLog()->create([
                        'field' => $field,
                        'old_value' => $old,
                        'new_value' => $new,
                        'changed_by_person_id' => $personId,
                    ]);
                }
            }

            $this->save();
        });
    }
}
