<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who created an account on User Administration and who approved it —
 * approved_at alone said when, not by whom. Null for self-registered
 * accounts (created_by), accounts nobody has approved yet, and everything
 * from before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('approved_by_person_id')->nullable()->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_person_id');
            $table->dropConstrainedForeignId('approved_by_person_id');
        });
    }
};
