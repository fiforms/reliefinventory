<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Open-ended admin-editable tag for what kind of party a Person is
     * (Donor, Supplier, Warehouse Contact, Recipient Organization, ...).
     * Deliberately not a hardcoded enum — mirrors the Category/ItemType
     * lookup-table idiom, since the real Flowtrac account-type list isn't
     * fixed and staff need to add categories (e.g. "Warehouse Contact")
     * without a code change.
     */
    public function up(): void
    {
        Schema::create('person_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_categories');
    }
};
