<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;
    
    // Define the table associated with the model
    protected $table = 'orderdonations';
    
    // Specify the fields
    protected $fillable = [
        'id', 
        'type',
        'user_id',    // Foreign key linking to users
        'person_id',  // Foreign key linking to people
        'status_id',  // Status ID associated with the order-donation relation
        'order_date', // Date of the transaction
        'comments',   // Additional notes
    ];
    
    
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
}
