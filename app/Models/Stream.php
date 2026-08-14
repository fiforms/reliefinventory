<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A pickup stream (Goodwill bin, recycler, disposal, ...): destination
 * partner + warehouse + what's counted + an optional threshold. Deliberately
 * not attached to pallets or people directly — orders have no threshold
 * concept, so this stays scoped to non-order outbound streams.
 */
class Stream extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'warehouse_id',
        'counts_kind',
        'counts_status',
        'counts_condition',
        'threshold',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * How many pallets currently match this stream's counting criteria.
     */
    public function currentCount(): int
    {
        $query = Pallet::query();

        if ($this->counts_kind) {
            $query->where('kind', $this->counts_kind);
        }
        if ($this->counts_status) {
            $query->where('status', $this->counts_status);
        }
        if ($this->counts_condition) {
            $query->where('condition', $this->counts_condition);
        }

        return $query->count();
    }

    public function isOverThreshold(): bool
    {
        return $this->threshold !== null && $this->currentCount() >= $this->threshold;
    }
}
