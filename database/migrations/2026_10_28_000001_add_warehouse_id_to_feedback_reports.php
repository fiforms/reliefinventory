<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which warehouse a feedback report came from (MULTI_WAREHOUSE.md §13): several warehouses can share one host, so
// the page URL's host alone doesn't tell central which one sent it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('warehouse_id', 8)->nullable()->after('instance_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropColumn('warehouse_id');
        });
    }
};
