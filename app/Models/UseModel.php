<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UseModel extends Model
{
    use HasFactory;

    protected $table = 'uses';

    protected $fillable = [
        'use',
    ];

    public function locations()
    {
        return $this->hasMany(Location::class, 'use_id');
    }
}
