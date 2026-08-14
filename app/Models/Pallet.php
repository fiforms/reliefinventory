<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Support\PalletKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A pallet record is one load's trip, not the physical wood underneath it
 * (the "new label per trip" / LPN rule) — reusing a pallet for a new load
 * means a new record with a new id, never resetting an existing one's kind
 * or history. A closed-out record (empty/shipped/collected/released/
 * condemned) is kept forever as donor/order provenance.
 */
class Pallet extends Model
{
    use HasFactory;

    protected $table = 'pallets';

    protected $fillable = [
        'kind',
        'status',
        'container_type',
        'donor_person_id',
        'destination_person_id',
        'truck_id',
        'orderdonation_id',
        'location_id',
        'datepacked',
        'earliest_expiry',
        'condition',
    ];

    protected $appends = ['tag'];

    /**
     * Full status history, oldest first — the append-only audit trail.
     */
    public function statuses()
    {
        return $this->hasMany(PalletStatus::class)->orderBy('created_at');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function donor()
    {
        return $this->belongsTo(Person::class, 'donor_person_id');
    }

    public function destination()
    {
        return $this->belongsTo(Person::class, 'destination_person_id');
    }

    public function truck()
    {
        return $this->belongsTo(Truck::class);
    }

    public function containers()
    {
        return $this->hasMany(Container::class);
    }

    // The donation (Receiving) this pallet belongs to, if any.
    public function donation()
    {
        return $this->belongsTo(Transaction::class, 'orderdonation_id');
    }

    /**
     * Printed/scanned tag: kind letter + zero-padded id ("R00000042").
     * Purely a human-readable label — lookups resolve by the numeric id
     * alone (see SortingSessionController::palletIdFromTag()), so kind
     * letters never need to be parsed back out of a tag.
     */
    public function getTagAttribute(): string
    {
        return $this->kind.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Transition to a new status, logging the change to history in the
     * same call. Does not enforce forward-only order — occasional manual
     * correction is expected — but does require the status to be valid for
     * this pallet's kind (or "missing").
     */
    public function transitionTo(string $status, ?int $locationId = null, ?string $notes = null): void
    {
        if (! PalletKind::isValidStatus($this->kind, $status)) {
            throw new \InvalidArgumentException("\"{$status}\" is not a valid status for pallet kind \"{$this->kind}\".");
        }

        DB::transaction(function () use ($status, $locationId, $notes) {
            $this->status = $status;
            if ($locationId !== null) {
                $this->location_id = $locationId;
            }
            if ($status === 'empty' && $this->condition === null) {
                // Empty-pallet QC starts pending; a supervisor (never the
                // sorter) later resolves it to good or condemned.
                $this->condition = 'pending';
            }
            $this->save();

            $this->statuses()->create([
                'location_id' => $locationId ?? $this->location_id,
                'status' => $status,
                'notes' => $notes,
            ]);

            $this->syncDonationStatus();
        });
    }

    /**
     * Push the donation status rollup in the same transaction as this
     * pallet's own status change — the one place this is ever triggered
     * from, so the two records never drift out of sync.
     */
    private function syncDonationStatus(): void
    {
        if ($this->orderdonation_id) {
            // Always re-fetch rather than use $this->donation: if this
            // Pallet instance is reused across more than one transition
            // (e.g. in a longer-lived process), a cached relation here
            // would carry stale sibling-pallet statuses into the rollup.
            Transaction::find($this->orderdonation_id)?->syncStatusFromPallets();
        }
    }

    /**
     * Mark this pallet missing — the universal exception status, reachable
     * from any lifecycle state. Remembers the prior status so a re-scan can
     * restore it.
     */
    public function markMissing(?string $notes = null): void
    {
        DB::transaction(function () use ($notes) {
            $this->status_before_missing = $this->status;
            $this->status = PalletKind::MISSING;
            $this->save();

            $this->statuses()->create([
                'location_id' => $this->location_id,
                'status' => PalletKind::MISSING,
                'notes' => $notes,
            ]);

            $this->syncDonationStatus();
        });
    }

    /**
     * Re-scanning a missing pallet restores its prior lifecycle status
     * rather than requiring someone to remember and re-enter it.
     */
    public function restoreFromMissing(): void
    {
        if ($this->status !== PalletKind::MISSING || ! $this->status_before_missing) {
            return;
        }

        $restored = $this->status_before_missing;

        DB::transaction(function () use ($restored) {
            $this->status = $restored;
            $this->status_before_missing = null;
            $this->save();

            $this->statuses()->create([
                'location_id' => $this->location_id,
                'status' => $restored,
                'notes' => 'Restored from missing on re-scan.',
            ]);

            $this->syncDonationStatus();
        });
    }
}
