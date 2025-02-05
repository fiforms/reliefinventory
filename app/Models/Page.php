<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

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

