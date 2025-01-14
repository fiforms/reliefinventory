<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = ['page_id', 'link_text', 'link_url', 'submenu_page_id', 'graphic_url', 'order'];

    /**
     * Get the page associated with the menu item.
     */
    public function page()
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the submenu page associated with this menu item.
     */
    public function submenuPage()
    {
        return $this->belongsTo(Page::class, 'submenu_page_id');
    }
}

