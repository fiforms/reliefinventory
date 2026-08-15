<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some donations arrive with genuinely no identifying information at
     * all — not even an organization name. Rather than leaving person_id
     * null (ambiguous: forgot to fill it in, vs. deliberately unknown), a
     * single canonical "Unknown Donor" Person record is selectable from the
     * same donor search/pick UI as any real donor. That gives "matching on
     * unknown" for free later — find every donation still pointed at this
     * one record and reconcile it once the real donor becomes known —
     * without inventing a parallel search mechanism.
     *
     * system_key marks it (and any future system-provided record) as
     * protected from deletion; deliberately NOT in Person::$fillable, so it
     * can only ever be set here, never through the People form/API.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('system_key', 50)->nullable()->unique()->after('id');
        });

        DB::table('people')->insert([
            'system_key' => 'unknown-donor',
            'organization' => 'Unknown Donor',
            'comments' => 'System-provided placeholder for donations whose source is genuinely unknown at intake. Reassign the donation to the real donor once identified; do not delete this record.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('people')->where('system_key', 'unknown-donor')->delete();

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('system_key');
        });
    }
};
