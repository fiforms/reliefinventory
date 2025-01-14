<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'categories';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'measurement_unit',
    ];

    /**
     * Get the items associated with the category.
     */
    public function items()
    {
        return $this->hasMany(Item::class, 'category_id');
    }
}
