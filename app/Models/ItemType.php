<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Helpers\UPCGenerator;

class ItemType extends Model
{
    use HasFactory;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'itemtypes';
    
    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
    
    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;
    
    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'number',
        'category_id',
        'unit_id',
        'name',
        'description',
        'active',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'active' => 'boolean',
    ];
    
    /**
     * Relationships
     */
    
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    
    public function items()
    {
        return $this->hasMany(Item::class, 'itemtype_id');
    }
    
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
    
    /**
     * Ensure all item numbers are exactly 5 digits (left-padded with zeros).
     * @return array Count of updated items.
     */
    public static function ensureFiveDigitItemNumbers()
    {
        $items = self::all();
        $updatedCount = 0;
        
        foreach ($items as $item) {
            if (!isset($item->number) || !ctype_digit($item->number)) {
                continue; // Skip if number is missing or not numeric
            }
            
            // Pad to 5 digits
            $correctedNumber = str_pad($item->number, 5, '0', STR_PAD_LEFT);
            
            // Only update if the number has changed
            if ($item->number !== $correctedNumber) {
                $item->update(['number' => $correctedNumber]);
                $updatedCount++;
            }
        }
        
        return ['updated' => $updatedCount];
    }
    
    /**
     * Ensure that all item types have exactly one generic item
     * @return array Count of updated items.
     */
    public static function refreshGenericItems()
    {
        $itemTypes = self::all();
        $createdCount = 0;
        $updatedCount = 0;
        
        foreach ($itemTypes as $itemType) {
            $generatedUPC = UPCGenerator::makeUPCFromItemNumber($itemType->number);
            
            // Check if a generic item exists
            $item = Item::where('itemtype_id', $itemType->id)
            ->where(function ($query) use ($generatedUPC) {
                $query->where('pluscode', '0000')
                ->orWhere('upc', $generatedUPC);
            })->first();
            
            // Define the correct values
            $correctValues = [
                'packagetypes_id' => 1, // Assuming default package type ID
                'pluscode' => '0000',
                'size' => 1.0,
                'case_qty' => 1,
                'active' => 1,
                'description' => $itemType->name . " GENERIC ITEM",
                'upc' => $generatedUPC,
            ];
            
            if ($item) {
                // If the item exists, check if it needs updating
                $needsUpdate = false;
                foreach ($correctValues as $key => $value) {
                    if ($item->$key != $value) {
                        $needsUpdate = true;
                        break;
                    }
                }
                
                if ($needsUpdate) {
                    $item->update($correctValues);
                    $updatedCount++;
                }
            } else {
                // If the item doesn't exist, create it
                Item::create(array_merge(['itemtype_id' => $itemType->id], $correctValues));
                $createdCount++;
            }
        }
        
        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
        ];
    }
}
