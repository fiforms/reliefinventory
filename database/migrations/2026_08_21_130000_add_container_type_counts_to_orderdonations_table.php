<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-container-type quantity (e.g. {"box": 4, "tote": 2}, or just
 * {"pallet": 8}) — a mixed load needs a separate count for each kind
 * selected in container_types, not one number that means something
 * different depending on what was picked. container_count keeps its
 * existing role as the derived total (computed client-side, see
 * Receiving.vue's computeContainerCount()) that the rest of the app
 * (list column, Describe Pallets progress) already reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->json('container_type_counts')->nullable()->after('container_types');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('container_type_counts');
        });
    }
};
