<?php

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
            'graphic_url' => '/images/order-entry-icon.png',
            'order' => 1
        ]);

        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Donation Entry',
            'link_url' => '/donation-entry',
            'graphic_url' => '/images/donation-entry-icon.png',
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
            'graphic_url' => '/images/edit-statuses-icon.png',
            'order' => 1
        ]);

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Master Item List',
            'link_url' => '/setup/items',
            'graphic_url' => '/images/edit-items-icon.png',
            'order' => 2
        ]);

        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Users',
            'link_url' => '/setup/users',
            'graphic_url' => '/images/edit-users-icon.png',
            'order' => 3
        ]);
    }
}

