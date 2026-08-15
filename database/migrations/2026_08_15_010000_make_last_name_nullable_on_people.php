<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A disaster-response donation often arrives with less identifying
     * information than the form previously assumed — sometimes just an
     * organization name ("it came from Walmart, no contact given"), never
     * a real first+last name to force into the record. first_name was
     * already nullable; last_name was not, forcing exactly this workaround
     * (typing the org name into both name fields). PeopleController now
     * requires first_name+last_name OR organization, not blanket name
     * fields.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('last_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('last_name')->nullable(false)->change();
        });
    }
};
