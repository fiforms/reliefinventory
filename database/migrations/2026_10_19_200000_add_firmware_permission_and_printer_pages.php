<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Firmware management leaves the device page for its own page under its own permission
 * (manage-firmware, Administrator by default — a bad release can brick a fleet, so it is
 * deliberately narrower than pairing/configuring devices). Also adds the all-printers page
 * (drag-to-order + status), which stays under manage-devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'manage-firmware')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'manage-firmware',
                'name' => 'manage-firmware',
                'description' => 'Upload and tag R Station firmware/software releases, and set the firmware source',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        $administratorId = DB::table('roles')->where('name', 'Administrator')->value('id');
        if ($administratorId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $administratorId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('permissions')->where('key', 'manage-devices')->update([
            'description' => 'Pair, configure, and revoke R Station devices and print servers, and set up their printers',
        ]);

        $devices = MenuItem::where('link_url', '/setup/devices')->first();
        if ($devices) {
            MenuItem::create([
                'page_id' => $devices->page_id, 'link_text' => 'R Station Firmware', 'link_url' => '/setup/firmware',
                'graphic_url' => $devices->graphic_url, 'order' => 66, 'group_label' => $devices->group_label,
                'permission_key' => 'manage-firmware',
            ]);
            MenuItem::create([
                'page_id' => $devices->page_id, 'link_text' => 'Printers', 'link_url' => '/setup/printers',
                'graphic_url' => '/img/printing-reports.webp', 'order' => 67, 'group_label' => $devices->group_label,
                'permission_key' => 'manage-devices',
            ]);
        }
    }

    public function down(): void
    {
        MenuItem::whereIn('link_url', ['/setup/firmware', '/setup/printers'])->delete();
        DB::table('permissions')->where('key', 'manage-firmware')->delete();
    }
};
