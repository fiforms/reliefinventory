<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donations don't just arrive palletized-or-not — they show up on pallets,
 * in boxes, bags, totes, or loose in the truck. Replaces the yes/no
 * arrived_palletized with a real container type, so "Quantity" that follows
 * on Receiving.vue actually means something (was previously always
 * "Number of Pallets" regardless of how it arrived).
 *
 * Best-effort data migration: true -> 'pallet' (the common case this
 * boolean was tracking), false -> null (we can't recover which of
 * box/bag/tote/loose it actually was).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->enum('container_type', ['pallet', 'box', 'bag', 'tote', 'loose'])
                ->nullable()
                ->after('arrived_palletized');
        });

        DB::table('orderdonations')->where('arrived_palletized', true)->update(['container_type' => 'pallet']);

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('arrived_palletized');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->boolean('arrived_palletized')->nullable()->after('container_type');
        });

        DB::table('orderdonations')->where('container_type', 'pallet')->update(['arrived_palletized' => true]);
        DB::table('orderdonations')->whereNotNull('container_type')->where('container_type', '!=', 'pallet')->update(['arrived_palletized' => false]);

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('container_type');
        });
    }
};
