<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One product on Training Mode's scanned list of real goods for the class — not stock (see App\Services\TrainingPreparer). */
class TrainingProduct extends Model
{
    protected $table = 'training_products';

    protected $fillable = ['item_id', 'qty'];

    protected $casts = ['qty' => 'integer'];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
