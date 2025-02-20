<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('menu_items')->where('link_url', '/setup/users')->delete();
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('menu_items')->insert([
            'page_id' => 3,
            'link_text' => 'Edit Users',
            'link_url' => '/setup/users',
            'submenu_page_id' => null,
            'graphic_url' => '/img/edit-users-icon.webp',
            'order' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
