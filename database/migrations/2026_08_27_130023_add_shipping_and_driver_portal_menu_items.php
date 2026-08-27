<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Shipping (staff: assign a driver, mark shipped) picks up right after
 * Order Filling in the main workflow group. Driver Portal is the same page
 * a driver reaches unauthenticated (phone + PIN) to upload a signed BOL —
 * also listed here so staff can find/preview it, even though drivers
 * themselves never see this menu (they have no account).
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::create([
            'page_id' => 1,
            'link_text' => 'Shipping',
            'link_url' => '/shipping',
            'graphic_url' => '/img/truck-delivery-icon.webp',
            'order' => 55,
            'permission_key' => 'manage-orders',
        ]);

        MenuItem::create([
            'page_id' => 1,
            'link_text' => 'Driver Portal',
            'link_url' => '/driver-portal',
            'graphic_url' => '/img/truck-box-icon.webp',
            'order' => 57,
            'permission_key' => 'manage-orders',
        ]);
    }

    public function down(): void
    {
        MenuItem::whereIn('link_url', ['/shipping', '/driver-portal'])->delete();
    }
};
