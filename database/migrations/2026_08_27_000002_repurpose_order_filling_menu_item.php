<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * /order-filling already has a real menu_items row (2025_02_07_032546), sat
 * on 'general-access' since 2026_08_14_021841 while served by the generic
 * ComingSoon placeholder. Repoint it now that the real page exists, rather
 * than inserting a second row — see routes/web.php's OrderFillingController
 * registration in the same deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItem::where('link_url', '/order-filling')->update(['permission_key' => 'manage-orders']);
    }

    public function down(): void
    {
        MenuItem::where('link_url', '/order-filling')->update(['permission_key' => 'general-access']);
    }
};
