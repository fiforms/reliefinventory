<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use App\Support\UserAgent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devices are no longer recorded just for visiting the login page (every browser became a "pending device", so the
 * list was useless). A device is now only recorded when someone logged in on it asks for PIN login there — an
 * admin with manage-trusted-devices approves it on the spot, anyone else files a request (optional station name
 * and reason) that an admin approves or denies. What the request was made from is kept: IP address, browser, OS.
 *
 * Every existing pending row was made automatically (no one ever asked), so they are removed; approved, revoked
 * rows stay, with browser/OS filled in from their user agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->foreignId('requested_by_person_id')->nullable()->after('status')->constrained('people')->nullOnDelete();
            $table->text('request_reason')->nullable()->after('requested_by_person_id');
            $table->string('ip_address', 45)->nullable()->after('user_agent');
            $table->string('last_ip_address', 45)->nullable()->after('ip_address');
            $table->string('browser', 100)->nullable()->after('last_ip_address');
            $table->string('os', 100)->nullable()->after('browser');
        });
        DB::statement("ALTER TABLE trusted_devices MODIFY status ENUM('pending', 'approved', 'revoked', 'denied') NOT NULL DEFAULT 'pending'");

        DB::table('trusted_devices')->where('status', 'pending')->delete();

        foreach (DB::table('trusted_devices')->whereNotNull('user_agent')->get(['id', 'user_agent']) as $row) {
            DB::table('trusted_devices')->where('id', $row->id)->update(UserAgent::parse($row->user_agent));
        }
    }

    public function down(): void
    {
        DB::table('trusted_devices')->where('status', 'denied')->update(['status' => 'revoked']);
        DB::statement("ALTER TABLE trusted_devices MODIFY status ENUM('pending', 'approved', 'revoked') NOT NULL DEFAULT 'pending'");
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by_person_id');
            $table->dropColumn(['request_reason', 'ip_address', 'last_ip_address', 'browser', 'os']);
        });
    }
};
