<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Mailing Labels (MailingLabelController) on the Reports menu. It lists names and
// addresses, so it is gated on manage-people rather than view-reports.
return new class extends Migration
{
    public function up(): void
    {
        $reports = DB::table('pages')->where('hashtag', 'reports')->value('id');
        if ($reports && ! DB::table('menu_items')->where('link_url', '/reports/mailing-labels')->exists()) {
            DB::table('menu_items')->insert([
                'page_id' => $reports, 'link_text' => 'Mailing Labels', 'link_url' => '/reports/mailing-labels',
                'graphic_url' => '/img/printing-reports.webp', 'order' => 65, 'permission_key' => 'manage-people',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menu_items')->where('link_url', '/reports/mailing-labels')->delete();
    }
};
