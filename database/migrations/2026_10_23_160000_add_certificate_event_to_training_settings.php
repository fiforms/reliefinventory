<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the training certificates print (Setup > Training Mode): the event's title, where the class was held, and two
 * trainers — each a name under a signature line and an optional uploaded signature image (a path on the private disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->string('event_title', 150)->nullable()->after('prepared_summary');
            $table->string('event_location', 150)->nullable()->after('event_title');
            $table->string('trainer1_name', 100)->nullable()->after('event_location');
            $table->string('trainer1_signature')->nullable()->after('trainer1_name');
            $table->string('trainer2_name', 100)->nullable()->after('trainer1_signature');
            $table->string('trainer2_signature')->nullable()->after('trainer2_name');
        });
    }

    public function down(): void
    {
        Schema::table('training_settings', function (Blueprint $table) {
            $table->dropColumn(['event_title', 'event_location', 'trainer1_name', 'trainer1_signature', 'trainer2_name', 'trainer2_signature']);
        });
    }
};
