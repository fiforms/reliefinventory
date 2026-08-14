<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    // Status names used for the donation lifecycle (received -> sorting ->
    // complete). Shared with the general Status lookup table rather than a
    // parallel column — SortingSessionController uses the same names.
    public const STATUS_RECEIVED = 'Received';

    public const STATUS_SORTING = 'Sorting';

    public const STATUS_COMPLETE = 'Complete';

    // Non-donation manifest entries (equipment/supplies/other) never enter
    // the donation lifecycle — this is their terminal status instead.
    public const STATUS_LOGGED = 'Logged';

    // Define the table associated with the model
    protected $table = 'orderdonations';

    // Specify the fields
    protected $fillable = [
        'id',
        'type',
        'category',    // donation | equipment | supplies | other — only "donation" enters the sorting pipeline
        'person_id_user', // Foreign key linking to people (the user who entered the transaction)
        'person_id',  // Foreign key linking to people
        'status_id',  // Status ID associated with the order-donation relation
        'order_date', // Date of the transaction
        'comments',   // Additional notes
        'container_count',
        'manifest',
        'manifest_weight_lbs',
    ];

    protected $casts = [
        'manifest_weight_lbs' => 'decimal:2',
        'status_changed_at' => 'datetime',
    ];

    /**
     * status_changed_at tracks every status_id change from one shared place
     * (not manually set per-controller), so the daily close-out report is a
     * plain indexed query instead of a reconstruction from history.
     */
    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction) {
            if ($transaction->isDirty('status_id')) {
                $transaction->status_changed_at = now();
            }
        });
    }

    // Define the relationship to the Status model
    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    // Define the relationship to the Person model
    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    // Orders have separate lines indicating the desired items
    public function orderLines()
    {
        return $this->hasMany(OrderLine::class, 'orderdonation_id');
    }

    // Define the relationship to ItemLedger model
    public function itemLedgers()
    {
        return $this->hasMany(ItemLedger::class, 'orderdonation_id');
    }

    // The person (user) who entered the transaction
    public function enteredBy()
    {
        return $this->belongsTo(Person::class, 'person_id_user');
    }

    // Receiving (R) pallets belonging to this donation
    public function pallets()
    {
        return $this->hasMany(Pallet::class, 'orderdonation_id');
    }

    /**
     * Asymmetric rollup: the FIRST pallet to leave "received" starts
     * sorting; the LAST pallet to reach "empty" completes the donation.
     * Called from Pallet::transitionTo() in the same DB transaction as the
     * triggering pallet change — the one shared place this ever happens,
     * so the stored status never drifts out of sync with its pallets.
     */
    public function syncStatusFromPallets(): void
    {
        if ($this->type !== 'donation') {
            return;
        }

        $pallets = $this->pallets;
        if ($pallets->isEmpty()) {
            return;
        }

        $currentStatusName = $this->status?->name;

        if ($currentStatusName === self::STATUS_RECEIVED && $pallets->contains(fn ($p) => $p->status !== 'received')) {
            $this->update(['status_id' => self::statusId(self::STATUS_SORTING)]);

            return;
        }

        if ($currentStatusName !== self::STATUS_COMPLETE && $pallets->every(fn ($p) => $p->status === 'empty')) {
            $this->update(['status_id' => self::statusId(self::STATUS_COMPLETE)]);
        }
    }

    public static function statusId(string $name): int
    {
        return Status::firstOrCreate(['name' => $name], ['description' => $name])->id;
    }
}
