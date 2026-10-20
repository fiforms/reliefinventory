<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderLine extends Model
{
    use HasFactory;

    protected $table = 'orderlines';

    protected $fillable = [
        'orderdonation_id',
        'itemtype_id',
        'packagetype_id',
        'qty_requested',
        'comments',
        'need_level',
    ];

    // qty_approved/approved_by_person_id/approved_at are deliberately not
    // fillable — only OrderFillingController::approveAllocation sets them.
    protected $casts = [
        'qty_approved' => 'integer',
        'approved_at' => 'datetime',
    ];

    protected $appends = ['qty_to_pick'];

    /**
     * What a picker should pull: the approved (allocated) quantity once a
     * manager has set one, otherwise the full request. A line whose
     * quantity to pick is 0 is left off pick sheets and the fill screen and
     * needs no fill record before the order can be completed.
     */
    public function getQtyToPickAttribute(): int
    {
        return (int) ($this->qty_approved ?? $this->qty_requested);
    }

    /**
     * Relationship: Each order line belongs to a transaction (order/donation).
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'orderdonation_id');
    }

    /**
     * Relationship: Each order line references an item type.
     */
    public function itemType()
    {
        return $this->belongsTo(ItemType::class, 'itemtype_id');
    }

    /**
     * Relationship: Each order line references a package type.
     */
    public function packageType()
    {
        return $this->belongsTo(PackageType::class, 'packagetype_id');
    }

    /**
     * Fill records (Order Filling/Picking) — each is an ItemLedger row with
     * qty_subtracted, keyed by order_line_id. Multiple allowed per line
     * (append-only, never overwritten); "filled so far" is their qty sum.
     */
    public function itemLedgers()
    {
        return $this->hasMany(ItemLedger::class, 'order_line_id');
    }
}
