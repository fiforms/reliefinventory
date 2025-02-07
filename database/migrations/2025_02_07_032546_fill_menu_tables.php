<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Page;
use App\Models\MenuItem;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        
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
            'order' => 10
        ]);
        
        MenuItem::create([
            'page_id' => $mainMenu->id,
            'link_text' => 'Donation Entry',
            'link_url' => '/donation-entry',
            'graphic_url' => '/img/donation-entry-icon.webp',
            'order' => 20
        ]);
        
        
        // Create the Report Menu page
        $reportMenu = Page::create([
            'menu_title' => 'Report Menu',
            'header_text' => 'Application Reports',
            'content' => 'Use this menu to configure statuses, items, and users.'
        ]);
        
        
        MenuItem::create([
            'page_id' => $reportMenu->id,
            'link_text' => 'Print Labels',
            'link_url' => '/reports/labels',
            'graphic_url' => '/img/barcode-printer.webp',
            'order' => 10
        ]);
        
        MenuItem::create([
            'page_id' => $reportMenu->id,
            'link_text' => 'Outstanding Orders',
            'link_url' => '/reports/orders',
            'graphic_url' => '/img/printing-reports.webp',
            'order' => 20
        ]);
        
        MenuItem::create([
            'page_id' => $reportMenu->id,
            'link_text' => 'Inventory Report',
            'link_url' => '/reports/inventory',
            'graphic_url' => '/img/reports.webp',
            'order' => 30
        ]);
        
        MenuItem::create([
            'page_id' => $reportMenu->id,
            'link_text' => 'Flow Reports',
            'link_url' => '/reports/flow',
            'graphic_url' => '/img/reports.webp',
            'order' => 40
        ]);
        
        MenuItem::create([
            'page_id' => $reportMenu->id,
            'link_text' => 'Donor Report',
            'link_url' => '/reports/donors',
            'graphic_url' => '/img/reports.webp',
            'order' => 50
        ]);
        
        MenuItem::create([
            'page_id' => $reportMenu->id,
            'link_text' => 'Customer Report',
            'link_url' => '/reports/customers',
            'graphic_url' => '/img/reports.webp',
            'order' => 60
        ]);
        
        // Create the Setup Menu page
        $setupMenu = Page::create([
            'menu_title' => 'Setup Menu',
            'header_text' => 'Application Setup Options',
            'content' => 'Use this menu to configure Items, Categories, Users etc.'
        ]);
        
        // Add menu items for the Setup Menu
        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Customer & Donor Info',
            'link_url' => '/setup/people',
            'graphic_url' => '/img/edit-items-icon.webp',
            'order' => 10
        ]);
        
        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Master Item List',
            'link_url' => '/setup/items',
            'graphic_url' => '/img/edit-items-icon.webp',
            'order' => 20
        ]);
        
        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Item Categories',
            'link_url' => '/setup/categories',
            'graphic_url' => '/img/edit-statuses-icon.webp',
            'order' => 30
        ]);
        
        MenuItem::create([
            'page_id' => $setupMenu->id,
            'link_text' => 'Edit Users',
            'link_url' => '/setup/users',
            'graphic_url' => '/img/edit-users-icon.webp',
            'order' => 50
        ]);
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
