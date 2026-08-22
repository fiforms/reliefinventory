<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional section heading a page can cluster its tiles under (e.g. Setup's
// "Warehouse Administration" vs "System Administration"). Null means no
// heading — the item renders in a page's default, ungrouped section, so
// every other page (Receiving, Orders, Reports, ...) is unaffected.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('group_label')->nullable()->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('group_label');
        });
    }
};
