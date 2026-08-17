<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * page_url now stores the full URL (scheme+host+path+query), not just
     * the path, so a report shows which instance (demo/wa26/prod) it came
     * from — the default 255-char varchar matched the validation rule
     * (max:2048) too loosely.
     */
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('page_url', 2048)->change();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('page_url', 255)->change();
        });
    }
};
