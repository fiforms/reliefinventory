<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Release version (GitVersionService::release()) the reporter's instance was running; null if unversioned. */
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('app_version', 64)->nullable()->after('commit_hash');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropColumn('app_version');
        });
    }
};
