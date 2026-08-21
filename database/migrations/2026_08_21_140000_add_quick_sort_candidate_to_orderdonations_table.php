<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dock-side judgment call, not a computed value: is this load mostly the
 * same item, so sorting can use the express lane (date check + count/
 * palletize) instead of full line-by-line sorting? Deliberately at the
 * donation level, not per-pallet — Receiving no longer tags individual
 * pallets with a specific catalog item (that required staff to open boxes/
 * bags to identify contents, which is sorting's job, not receiving's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->boolean('quick_sort_candidate')->nullable()->after('container_type_counts');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('quick_sort_candidate');
        });
    }
};
