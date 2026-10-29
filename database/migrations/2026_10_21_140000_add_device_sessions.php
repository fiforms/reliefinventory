<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device sessions: lets a paired R Station's browser show a small allow-list of pages with nobody
 * logged in. The Pi's local backend (which holds the bearer token) trades it for a short-lived,
 * single-use ticket; the browser redeems the ticket for a cookie whose hash is kept on the
 * device row. Only hashes are stored, never the ticket or cookie value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->string('session_token_hash', 64)->nullable()->unique()->after('token_hash');
        });

        Schema::create('device_session_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_device_id')->constrained('managed_devices')->cascadeOnDelete();
            $table->string('ticket_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_session_tickets');
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->dropColumn('session_token_hash');
        });
    }
};
