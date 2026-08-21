<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mixed-arrival shipment can be several loose box AND bag AND tote AND
 * loose items at once, so this needs to hold a set, not one value — but
 * Pallets stays a single, exclusive choice (Receiving.vue's top-level
 * question is Pallets vs. Other, where Other reveals a multi-select
 * checklist of box/bag/tote/loose). Storing a JSON array rather than a
 * separate "is_mixed" flag + set means there's exactly one place a
 * container_types value comes from — ['pallet'] or some subset of
 * box/bag/tote/loose — instead of two columns that could disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->json('container_types')->nullable()->after('container_type');
        });

        DB::table('orderdonations')->whereNotNull('container_type')->orderBy('id')->each(function ($row) {
            DB::table('orderdonations')->where('id', $row->id)->update([
                'container_types' => json_encode([$row->container_type]),
            ]);
        });

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('container_type');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->enum('container_type', ['pallet', 'box', 'bag', 'tote', 'loose'])
                ->nullable()
                ->after('container_types');
        });

        DB::table('orderdonations')->whereNotNull('container_types')->orderBy('id')->each(function ($row) {
            $types = json_decode($row->container_types, true) ?: [];
            DB::table('orderdonations')->where('id', $row->id)->update([
                'container_type' => count($types) === 1 ? $types[0] : null,
            ]);
        });

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('container_types');
        });
    }
};
