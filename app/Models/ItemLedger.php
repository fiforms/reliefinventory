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
        'qty_added',        // Quantity of the items added
        'qty_subtracted',   // Quanitity of items removed
        'transaction_type', // Type of transaction (e.g., 'addition', 'removal')
        'reference_id',     // Reference ID for the related entity (e.g., order, donation)
        'description',      // Description of the transaction
        'created_by',       // User ID who created the transaction
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
    
    // Define the relationship to the User model (creator of the transaction)
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
