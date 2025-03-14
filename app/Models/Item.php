<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'items';
    
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
        'itemtype_id',
        'packagetypes_id',
        'pluscode',
        'size',
        'case_qty',
        'active',
        'description',
        'upc',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'size' => 'decimal:2',
        'case_qty' => 'integer',
        'active' => 'boolean',
    ];
    
    /**
     * Relationships
     */
    
    public function itemtype()
    {
        return $this->belongsTo(ItemType::class, 'itemtype_id');
    }
    
    public function packagetype()
    {
        return $this->belongsTo(PackageType::class, 'packagetypes_id');
    }
    
    // Add other relationships as necessary
}
