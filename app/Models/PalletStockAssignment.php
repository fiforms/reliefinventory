<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Stock put on (or corrected on) a warehouse pallet. Append-only; see PalletStock. */
class PalletStockAssignment extends Model
{
    protected $fillable = ['pallet_id', 'item_id', 'qty', 'person_id_user', 'notes'];

    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
