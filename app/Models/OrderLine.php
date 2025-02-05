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
    ];

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
}
