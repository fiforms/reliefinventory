<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pin_hash: self-service, set by the person themselves (see
     * UpdatePinForm.vue / PinController) — a bcrypt hash of a 5-digit PIN,
     * deliberately kept out of Person::$fillable so it can only ever be
     * written by that dedicated, hashing-aware controller, never by a raw
     * mass-assignment payload through PeopleController.
     *
     * badge_code: admin-assigned (the physical badge is issued by staff,
     * not chosen by the volunteer), used to resolve a scanned badge to a
     * person during unlock. Unique so a scan can never resolve ambiguously.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
            $table->string('badge_code')->nullable()->unique()->after('pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'badge_code']);
        });
    }
};
