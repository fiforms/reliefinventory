<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A device (identified by an opaque token in a long-lived cookie, never
     * anything identifying the physical hardware itself) must be explicitly
     * approved by an admin before PIN unlock is offered on it at all — the
     * hardening step on top of per-person trust grants
     * (device_trust_grants): even a device where someone has genuinely
     * logged in with their real password can't offer PIN quick-switch
     * unless it's also on this allow-list. A lost/stolen laptop that was
     * never approved here gets nothing but "log in with email," no matter
     * who logged into it once.
     */
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_token', 64)->unique();
            $table->string('label')->nullable(); // admin-assigned, e.g. "Sorting Station 1"
            $table->enum('status', ['pending', 'approved', 'revoked'])->default('pending');
            $table->string('user_agent')->nullable(); // captured at first sight, helps an admin tell devices apart
            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
