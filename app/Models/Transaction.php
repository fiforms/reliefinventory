<?php

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
        'person_id', // Foreign key linking to users
        'status_id', // Status ID associated with the order-donation relation
        'order_date', // Date of the transaction
        'comments', // Additional notes
    ];
    
    
    // Define the relationship to the Status model
    public function status()
    {
        return $this->belongsTo(Status::class);
    }
    
    // Define the relationship to ItemLedger model
    public function itemLedgers()
    {
        return $this->hasMany(ItemLedger::class, 'orderdonation_id');
    }
}
