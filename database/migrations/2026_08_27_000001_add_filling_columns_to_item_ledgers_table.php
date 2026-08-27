<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Filling/Picking needs to tie a stock subtraction back to the specific
 * requested line it fulfills (order_line_id, mirroring pallet_id's nullable
 * soft-linking pattern) and, since item_ledgers has never had any actor
 * tracking at all, an audit column (person_id_user) — set from Auth::id()
 * server-side, never client-supplied. Sorting's own writes start stamping
 * this too (see SortingSessionController), so the column isn't half-null by
 * design the moment it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->foreignId('order_line_id')->nullable()->after('pallet_id')
                ->constrained('orderlines')->nullOnDelete();
            $table->foreignId('person_id_user')->nullable()->after('disposition')
                ->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_line_id');
            $table->dropConstrainedForeignId('person_id_user');
        });
    }
};
