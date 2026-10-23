<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The training event's title and where the class was held, printed on the certificates (Setup > Training Mode). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->string('event_title', 150)->nullable()->after('prepared_summary');
            $table->string('event_location', 150)->nullable()->after('event_title');
        });
    }

    public function down(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->dropColumn(['event_title', 'event_location']);
        });
    }
};
