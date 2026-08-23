<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable expansion of the kiosk's "Other" sign-in category (state
 * representative, maintenance/repair, ...) — mirrors the
 * ItemType/PackageType/PersonCategory lookup-table idiom rather than a
 * hardcoded enum. A free-text catch-all still lives on volunteer_sign_ins
 * itself for whatever isn't in this list yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_sign_in_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_sign_in_categories');
    }
};
