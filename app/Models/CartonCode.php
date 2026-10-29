<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A person-confirmed carton barcode -> contained item + units per carton (see the create_carton_codes migration). */
class CartonCode extends Model
{
    protected $fillable = ['code', 'item_id', 'qty_per_carton', 'inner_packs', 'items_per_pack', 'person_id_user'];

    protected $casts = ['qty_per_carton' => 'integer', 'inner_packs' => 'integer', 'items_per_pack' => 'integer'];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function enteredBy()
    {
        return $this->belongsTo(Person::class, 'person_id_user');
    }
}
