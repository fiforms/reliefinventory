<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets staff give a driver a PIN so they can log into the Driver Portal
 * (phone + PIN, no full account — see the driver-portal-and-bol-upload
 * design notes) and upload a signed BOL for their own assigned loads.
 * Reuses HasPinLogin, the same trait Person already uses for the
 * shared-terminal PIN-unlock feature — hashing/verification logic is
 * identical, only the owning model differs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('carrier');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('pin_hash');
        });
    }
};
