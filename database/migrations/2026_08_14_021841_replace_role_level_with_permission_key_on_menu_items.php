<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menu visibility used the same numeric role_level magnitude comparison
 * CheckRole did — and had already drifted from actual route access
 * (/setup/items required role_level=4096 in the menu but only role:4 on
 * its route; /receiving had no role_level at all, so it was shown to
 * every authenticated user despite the route requiring role:4). Replacing
 * it with the same permission keys the routes themselves now use closes
 * that gap instead of maintaining two separate, driftable access lists.
 */
return new class extends Migration
{
    private const PERMISSION_KEYS = [
        '/order-entry' => null, // visible to everyone authenticated, matches the route
        '/receiving' => 'manage-receiving',
        '/order-filling' => 'general-access',
        '/donation-sorting' => 'manage-sorting',
        '/inventory-movement' => 'manage-pallets',
        '#reports' => 'general-access',
        '#setup' => 'general-access',
        '/reports/labels' => 'manage-items',
        '/reports/orders' => 'general-access',
        '/reports/inventory' => 'general-access',
        '/reports/flow' => 'general-access',
        '/reports/donors' => 'general-access',
        '/reports/customers' => 'general-access',
        '#main' => null,
        '/setup/people' => 'manage-people',
        '/setup/items' => 'manage-items',
        '/setup/categories' => 'manage-categories',
        '/setup/locations' => 'manage-locations',
        '/setup/users' => 'general-access',
    ];

    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('permission_key')->nullable()->after('role_level');
        });

        foreach (self::PERMISSION_KEYS as $linkUrl => $key) {
            DB::table('menu_items')->where('link_url', $linkUrl)->update(['permission_key' => $key]);
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('role_level');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->integer('role_level')->default(0);
            $table->dropColumn('permission_key');
        });
    }
};
