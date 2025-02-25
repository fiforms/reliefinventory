<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->integer('role_level')->default(0)->after('order');
        });
            
            // Update the role_level based on the link_url
            $roleLevels = [
                '/order-entry' => 0,
                '/order-filling' => 4,
                '/donation-sorting' => 4,
                '/inventory-movement' => 4,
                '#reports' => 4,
                '#setup' => 4,
                '/reports/labels' => 4,
                '/reports/orders' => 4,
                '/reports/inventory' => 4,
                '/reports/flow' => 4,
                '/reports/donors' => 4,
                '/reports/customers' => 4,
                '#main' => 0,
                '/setup/people' => 4,
                '/setup/items' => 4096,
                '/setup/categories' => 4096,
                '/setup/locations' => 4096,
            ];
            
            foreach ($roleLevels as $linkUrl => $roleLevel) {
                DB::table('menu_items')
                ->where('link_url', $linkUrl)
                ->update(['role_level' => $roleLevel]);
            }
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('role_level');
        });
    }
};
