<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The Pi device fleet page was first labelled "Kiosk Devices", which collided
// with the volunteer sign-in kiosk. Relabel the menu item (link stays /setup/devices).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu_items')->where('link_url', '/setup/devices')->where('link_text', 'Kiosk Devices')
            ->update(['link_text' => 'R Station Devices']);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('link_url', '/setup/devices')->where('link_text', 'R Station Devices')
            ->update(['link_text' => 'Kiosk Devices']);
    }
};
