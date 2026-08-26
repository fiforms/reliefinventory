<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Type-ahead suggestion values for the kiosk's Agency and Task
 * (title_function) free-text fields — one table for both kinds since
 * they're the same shape (an admin-managed list of strings feeding a
 * <datalist>, with the underlying field always staying free text). Global,
 * not per-location, unlike sign_in_categories' guest types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('kind'); // 'agency' | 'task'
            $table->string('value');
            $table->timestamps();

            $table->unique(['kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_suggestions');
    }
};
