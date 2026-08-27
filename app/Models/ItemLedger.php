<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemLedger extends Model
{
    use HasFactory;
    
    // Define the table associated with the model
    protected $table = 'item_ledgers';
    
    // Specify the fillable fields
    protected $fillable = [
        'orderdonation_id', // Foreign key linking to transactions
        'item_id',          // Foreign key linking to master list of items and descriptions
        'pallet_id',        // Foreign key linking to the source pallet (donation provenance)
        'order_line_id',    // Foreign key linking to the order line this fill record satisfies
        'qty_added',        // Quantity of the items added
        'qty_subtracted',   // Quanitity of items removed
        'disposition',      // usable | trashed | diverted
        'transaction_type', // Type of transaction (e.g., 'addition', 'removal')
        'reference_id',     // Reference ID for the related entity (e.g., order, donation)
        'description',      // Description of the transaction
        'created_by',       // User ID who created the transaction
        // Fillable, matching Transaction::person_id_user's own precedent —
        // the safeguard is that controllers never source this from request
        // input (never $request->validate()'d), always Auth::id() built
        // directly into the create()/fill() call.
        'person_id_user',
    ];
    
    /**
     * Relationships
     */
    
    // Define the relationship to the Item model
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
    
    public function pallet()
    {
        return $this->belongsTo(Pallet::class);
    }
    
    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function orderLine()
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function personUser()
    {
        return $this->belongsTo(Person::class, 'person_id_user');
    }
    
    // Define the relationship to the User model (creator of the transaction)
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
