<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional best-by date a sorter records on a sorted line. Null = no date known. Sorting's "combine duplicate
 * lines" keeps different dates apart; Fill Pallet suggests the earliest date of what goes on a pallet (which is
 * stored on pallets.earliest_expiry, a column that already existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('item_condition');
        });
    }

    public function down(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->dropColumn('expires_on');
        });
    }
};
