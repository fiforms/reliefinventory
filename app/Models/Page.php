<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $fillable = ['menu_title', 'header_text', 'content'];

    /**
     * Get the menu items associated with the page.
     */
    public function menuItems()
    {
        return $this->hasMany(MenuItem::class);
    }
}

