<?php
// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Page;
use App\Models\MenuItem;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Create the Main Menu page
        $mainMenu = Page::create([
            'menu_title' => 'Main Menu',
            'header_text' => 'Welcome to the Relief Inventory Application',
            'content' => 'This is the main menu where you can navigate to various parts of the application.'
        ]);

        // Add menu items for the Main Menu
        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Order Entry',
            'link_url' => '/order-entry',
            'graphic_url' => '/img/order-entry-icon.webp',
            'order' => 1
        ]);

        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Donation Entry',
            'link_url' => '/donation-entry',
            'graphic_url' => '/img/donation-entry-icon.webp',
            'order' => 2
        ]);

        // Create the Setup Menu page
        $setupMenu = Page::create([
            'menu_title' => 'Setup Menu',
            'header_text' => 'Application Setup Options',
            'content' => 'Use this menu to configure statuses, items, and users.'
        ]);

        // Add menu items for the Setup Menu
        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Statuses',
            'link_url' => '/setup/statuses',
            'graphic_url' => '/img/edit-statuses-icon.webp',
            'order' => 1
        ]);

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Master Item List',
            'link_url' => '/setup/items',
            'graphic_url' => '/img/edit-items-icon.webp',
            'order' => 2
        ]);

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Users',
            'link_url' => '/setup/users',
            'graphic_url' => '/img/edit-users-icon.webp',
            'order' => 3
        ]);
    }
}

