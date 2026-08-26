<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes sign_in_categories per kiosk location — this list is now also the
 * kiosk's Guest-flow "type" picker (Maintenance/Repair, FEMA, State, ...),
 * and different locations want different rosters of those, always with a
 * free-text "Other" alongside. Existing rows backfill onto the seeded
 * default location. Uniqueness moves from a bare name to (location, name),
 * since the same type name can legitimately exist at more than one
 * location. Seeds three starter examples on the default location so the
 * new Guest Types settings card isn't empty on first visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_in_categories', function (Blueprint $table) {
            $table->foreignId('kiosk_location_id')->nullable()->after('id')
                ->constrained('kiosk_locations')->cascadeOnDelete();
        });

        DB::table('sign_in_categories')->whereNull('kiosk_location_id')->update(['kiosk_location_id' => 1]);

        Schema::table('sign_in_categories', function (Blueprint $table) {
            $table->foreignId('kiosk_location_id')->nullable(false)->change();
            // Named explicitly: the unique index still carries its
            // original pre-rename name (RENAME TABLE doesn't rename
            // indexes), not the sign_in_categories_name_unique Laravel's
            // column-array form would look for.
            $table->dropUnique('volunteer_sign_in_categories_name_unique');
            $table->unique(['kiosk_location_id', 'name']);
        });

        foreach (['Maintenance/Repair', 'FEMA', 'State'] as $name) {
            DB::table('sign_in_categories')->insertOrIgnore([
                'kiosk_location_id' => 1,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sign_in_categories', function (Blueprint $table) {
            $table->dropUnique(['kiosk_location_id', 'name']);
            $table->dropConstrainedForeignId('kiosk_location_id');
            $table->unique('name');
        });
    }
};
