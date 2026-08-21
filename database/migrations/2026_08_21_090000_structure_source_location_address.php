<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the single freeform source_location text field with a structured
 * address (street/city/state/zip), matching both the real MachForm Manifest
 * form and the structured address People already carries. The existing
 * column is renamed rather than dropped so previously-entered freeform text
 * (which might be a full "dropped near Asheville, NC" style description,
 * not just a street) isn't discarded — it becomes the street-line value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL rename (not Schema::renameColumn) — doctrine/dbal isn't a
        // dependency of this app, and renameColumn requires it.
        DB::statement('ALTER TABLE orderdonations CHANGE source_location source_address TEXT NULL');

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('source_city')->nullable()->after('source_address');
            $table->string('source_state', 2)->nullable()->after('source_city');
            $table->string('source_zip', 10)->nullable()->after('source_state');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn(['source_city', 'source_state', 'source_zip']);
        });

        DB::statement('ALTER TABLE orderdonations CHANGE source_address source_location TEXT NULL');
    }
};
