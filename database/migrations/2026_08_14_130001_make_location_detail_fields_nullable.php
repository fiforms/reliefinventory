<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Only code, use, and status are required on a location; the physical
// coordinates (route/block/aisle/lane/stack) and pull sequence are optional
// detail. use_id stays nullable at the DB level (its FK is onDelete set-null);
// requiredness is enforced by LocationController validation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->integer('PullSequence')->nullable()->change();
            $table->string('Route')->nullable()->change();
            $table->string('Block')->nullable()->change();
            $table->string('Aisle')->nullable()->change();
            $table->string('Lane')->nullable()->change();
            $table->string('Stack')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->integer('PullSequence')->nullable(false)->change();
            $table->string('Route')->nullable(false)->change();
            $table->string('Block')->nullable(false)->change();
            $table->string('Aisle')->nullable(false)->change();
            $table->string('Lane')->nullable(false)->change();
            $table->string('Stack')->nullable(false)->change();
        });
    }
};
