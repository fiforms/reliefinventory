<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New vs. used for usable goods recorded at sorting (feedback #42). Kept
 * separate from disposition on purpose: both new and used goods are
 * `usable` and count toward stock on hand exactly as before — condition
 * only records which they were, for reporting (states and community
 * partners ask specifically about new items). Named item_condition because
 * CONDITION is a reserved word in MariaDB. Null on non-usable rows, on
 * fill (subtraction) rows, and on rows sorted before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->string('item_condition', 10)->nullable()->after('disposition');
        });
    }

    public function down(): void
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->dropColumn('item_condition');
        });
    }
};
