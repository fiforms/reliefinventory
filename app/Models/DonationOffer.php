<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pre-arrival donation lifecycle: someone calls to offer a donation before
 * anything ships. See donation_offers migration + DonationOfferStatusLog
 * for the design. Not every donation goes through this — a Transaction can
 * exist with no offer behind it (walk-in drop-off).
 */
class DonationOffer extends Model
{
    public const STATUS_OFFERED = 'offered';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_DIVERTED = 'diverted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_RECEIVED = 'received';

    /**
     * Legal moves out of each status. "accepted" never appears as a column
     * value — it's the transition that lands on STATUS_PENDING.
     */
    private const TRANSITIONS = [
        self::STATUS_OFFERED => [self::STATUS_PENDING, self::STATUS_REFUSED, self::STATUS_DIVERTED],
        self::STATUS_PENDING => [self::STATUS_CANCELLED, self::STATUS_RECEIVED],
    ];

    protected $fillable = [
        'person_id',
        'contact_person_id',
        'status',
        'eta_start',
        'eta_end',
        'transit_notes',
        'description',
        'entered_by_person_id',
    ];

    // eta_start/eta_end are deliberately NOT cast to Carbon: an Eloquent
    // 'date' cast serializes to a full ISO datetime string in JSON, which a
    // browser parses as UTC midnight and can then render as the wrong local
    // calendar day (e.g. one day early in a timezone west of UTC) — the
    // same trap Transaction::needed_by_date avoids by staying uncast. Left
    // as the raw "YYYY-MM-DD" string MySQL returns, which binds directly to
    // an <input type="date">; use Carbon::parse() here when date math is
    // actually needed.

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function contactPerson()
    {
        return $this->belongsTo(Person::class, 'contact_person_id');
    }

    public function enteredBy()
    {
        return $this->belongsTo(Person::class, 'entered_by_person_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(DonationOfferStatusLog::class)->orderBy('created_at');
    }

    public function donation()
    {
        return $this->belongsTo(Transaction::class, 'donation_id');
    }

    /**
     * Compact display string for the ETA date range — a single date when
     * eta_end is unset or matches eta_start, otherwise "M j – M j".
     */
    public function etaRangeLabel(): ?string
    {
        if (! $this->eta_start) {
            return null;
        }

        $start = Carbon::parse($this->eta_start);

        if (! $this->eta_end || $this->eta_end === $this->eta_start) {
            return $start->format('M j');
        }

        return $start->format('M j').' – '.Carbon::parse($this->eta_end)->format('M j');
    }

    /**
     * Move to a new status, applying any accompanying column updates (e.g.
     * eta_start/eta_end/transit_notes on accept, refused_reason on refuse, donation_id on
     * match) and appending an audit-log row, all in one transaction — the
     * only place this model's status column is ever written. Mirrors
     * Pallet::transitionTo().
     */
    public function transitionTo(
        string $toStatus,
        ?int $personId,
        array $columnUpdates = [],
        ?string $contactMethod = null,
        ?string $notes = null
    ): void {
        if (! in_array($toStatus, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw new InvalidArgumentException("Cannot move a donation offer from \"{$this->status}\" to \"{$toStatus}\".");
        }

        DB::transaction(function () use ($toStatus, $personId, $columnUpdates, $contactMethod, $notes) {
            $from = $this->status;
            $this->status = $toStatus;
            foreach ($columnUpdates as $column => $value) {
                $this->{$column} = $value;
            }
            $this->save();

            $this->statusLogs()->create([
                'from_status' => $from,
                'to_status' => $toStatus,
                'changed_by_person_id' => $personId,
                'contact_method' => $contactMethod,
                'notes' => $notes,
            ]);
        });
    }
}
