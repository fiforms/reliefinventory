<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * role_bitpack is fully superseded: route/action gating now goes through
 * the permissions model (CheckPermission), and PeopleController's
 * escalation check (can't grant a permission you don't hold yourself) now
 * reasons in permission-key terms instead of bits. No production data
 * exists anywhere for this system, so this is a clean removal, not a data
 * migration — see the numbering/pallet-model precedent for the same call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('role_bitpack');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->unsignedBigInteger('role_bitpack')->default(0);
        });
    }
};
