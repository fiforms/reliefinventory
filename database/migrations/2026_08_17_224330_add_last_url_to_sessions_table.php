<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the "who's logged in" admin view — sessions already tracks
     * user_id/ip_address/last_activity for the database session driver,
     * this just adds the one missing piece (current page) so the view can
     * show what someone is doing, not just that they're online.
     */
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->string('last_url')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('last_url');
        });
    }
};
