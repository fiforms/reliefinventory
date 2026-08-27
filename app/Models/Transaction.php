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

    // Order lifecycle (New Order -> Ready to Fill -> Filling -> Filled ->
    // Shipped). "Order" is the backend/DB term throughout — the `type`
    // column, these status strings, the manage-orders permission key,
    // OrderController, /json/orders routes — and stays "order" there
    // deliberately (renaming stored strings/permission keys is a bigger,
    // separate decision than a UI label swap). Partner-facing UI says
    // "Request" instead (Tim/Statesville's original vocabulary was
    // "warehouse request form" — "order" reads retail-adjacent the same
    // way "customer" did, see the 2026-08-22 Customer->Partner rename).
    // Only "New Order" is intake-editable — completing the
    // Review & Confirm screen (OrderController::complete) moves it to
    // "Ready to Fill", which locks it the same as any other non-New-Order
    // status. The rest progress from filling actions, never from the entry
    // form. See the order-fulfillment-lifecycle-design memory for the
    // larger (not yet built) picture this sits inside — location tracking,
    // a Ready to Ship status, and a pickup-vs-ship terminus.
    public const STATUS_NEW_ORDER = 'New Order';

    public const STATUS_READY_TO_FILL = 'Ready to Fill';

    public const STATUS_FILLING = 'Filling';

    public const STATUS_FILLED = 'Filled';

    // Ready to Ship: staff have assigned a driver (Shipping page,
    // ShippingController::assign) to a Filled order. Shipped: staff have
    // confirmed the load physically left the warehouse
    // (ShippingController::markShipped) — a manual, warehouse-observed
    // event, not something the app infers. Delivered: the driver (or
    // staff, on their behalf) has uploaded the signed BOL through the
    // Driver Portal — see driver-portal-and-bol-upload design notes.
    public const STATUS_READY_TO_SHIP = 'Ready to Ship';

    public const STATUS_SHIPPED = 'Shipped';

    // Delivered is a "pending manager review" holding state, not the
    // terminus — added same-day (2026-08-27) once Mark asked for a
    // sign-off step: a manager reviews the uploaded signed BOL on the
    // Shipping page and either approves it (-> Completed, this order
    // type's real terminus now) or rejects it, which sends the order back
    // to Shipped so the driver sees it again in the Driver Portal and
    // re-uploads (bol_rejection_reason carries why, shown on their load
    // card). See ShippingController::approve()/reject().
    public const STATUS_DELIVERED = 'Delivered';

    public const STATUS_COMPLETED = 'Completed';

    // Define the table associated with the model
    protected $table = 'orderdonations';

    // Specify the fields
    protected $fillable = [
        'id',
        'type',
        'category',    // donation | equipment | supplies | other — only "donation" enters the sorting pipeline
        'category_other', // free text when category = other
        'person_id_user', // Foreign key linking to people (the user who entered the transaction)
        'person_id',  // Foreign key linking to people
        // The person to contact about THIS shipment — distinct from person_id
        // (the donor/org itself). A real, reusable Person (an org contact),
        // not free text — see the 2026_08_21 contact_person_id migration.
        'contact_person_id',
        'donor_identification_pending', // flagged for donor follow-up — see the 2026_08_15 migration
        'status_id',  // Status ID associated with the order-donation relation
        'order_date', // Date of the transaction — staff-editable, defaults to today (see ReceivingController)
        'comments',   // Additional notes
        'container_count',
        'manifest',
        'manifest_weight_lbs',
        'driver_id', // Foreign key to drivers — see the 2026_08_21 migration replacing driver_name/driver_phone
        'arrival_method', // semi | box_truck | personal_vehicle | delivery_truck | trailer | other
        'arrival_method_other', // free text when arrival_method = other
        'carrier', // free text when arrival_method = delivery_truck (UPS/FedEx/Amazon/etc)
        'container_types', // JSON array — ['pallet'] (exclusive) or a subset of box/bag/tote/loose; informational, never gates container_count
        'container_type_counts', // JSON map of the above -> quantity, e.g. {"box": 4, "tote": 2}; container_count is their derived sum
        'quick_sort_candidate', // dock-side judgment call: mostly one item, eligible for sorting's express lane — see the 2026_08_21 migration
        'source_address', // where this donation came from — always captured, independent of donor_identification_pending
        'source_city',
        'source_state',
        'source_zip',
        'photo_path', // reference photo of the shipment/load — served via ReceivingController::photo()

        // Order Review & Confirm fields (type=order only) — see the
        // 2026_08_18 migrations. delivery_days/preferred_time only apply to
        // fulfillment_method=delivery — OrderController::complete() force-
        // clears both when it's pickup (the warehouse sets pickup days/
        // times, not the partner); needed_by_date is shared by both.
        'fulfillment_method',
        'needed_by_date',
        'delivery_days', // array of Sun..Sat; the UI defaults/represents "Any Day" as all 7 selected
        'preferred_time',
        'contact_name',
        'contact_phone',
        'other_needs',
        // Delivery-only instructions for the driver (gate codes, dock
        // location, contact-on-arrival) — distinct from other_needs, which
        // is additional requested *items*. Carried through to the BOL.
        'special_instructions',
        // The driver's returned signed/scanned BOL — always set server-side
        // via an explicit ->update()/property assignment, never from a
        // client request payload directly. See ShippingController/
        // DriverPortalController.
        'signed_bol_path',
        // Manager's note on why a signed BOL was rejected, shown to the
        // driver — set server-side by ShippingController::reject(), not
        // from an arbitrary client payload. bol_reviewed_by_person_id is
        // NOT fillable — it's an actor field, always set from Auth::id().
        'bol_rejection_reason',
        // See Person::$fillable's source_system/source_ref comment.
        'source_system',
        'source_ref',
    ];

    protected $casts = [
        'manifest_weight_lbs' => 'decimal:2',
        'donor_identification_pending' => 'boolean',
        'status_changed_at' => 'datetime',
        'delivery_days' => 'array',
        'container_types' => 'array',
        'container_type_counts' => 'array',
        'quick_sort_candidate' => 'boolean',
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

    // The person to contact about this specific shipment — see
    // contact_person_id's comment in $fillable above.
    public function contactPerson()
    {
        return $this->belongsTo(Person::class, 'contact_person_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
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

    // The pre-arrival DonationOffer this intake was matched to, if any.
    public function donationOffer()
    {
        return $this->hasOne(DonationOffer::class, 'donation_id');
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
