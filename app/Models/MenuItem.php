<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = ['page_id', 'link_text', 'link_url', 'submenu_page_id', 'graphic_url', 'order', 'group_label', 'permission_key'];

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

    /**
     * Recursively build the breadcrumb trail.
     *
     * @param  string  $url
     * @return array
     */
    public static function getBreadcrumb($url)
    {
        // Matched on the path only, ignoring any query string on the stored
        // link_url (e.g. the Volunteer Kiosk tile's "/volunteers/kiosk?enable=1")
        // — callers always pass the plain path, and the breadcrumb itself
        // should link to the plain page rather than re-triggering whatever
        // one-time query-driven behavior the tile's own link carries.
        $menuItem = self::whereRaw("SUBSTRING_INDEX(link_url, '?', 1) = ?", [$url])->first();

        $breadcrumbs = [[
            'href' => $url,
            'title' => $menuItem->link_text,
        ]];

        $page = $menuItem->page;

        while ($page && $page->id > 1) {

            array_unshift($breadcrumbs, [
                'href' => '/dashboard#'.$page->hashtag,
                'title' => $page->menu_title,
            ]);

            $linkingmenu = self::where('link_url', '#'.$page->hashtag)->first();
            $page = $linkingmenu->page;
        }

        return $breadcrumbs;
    }
}
