<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training Mode's product list (Setup > Training Mode > Scan Training Products): the real goods a trainer has for the
 * class, scanned one unit at a time — item row (the SKU the barcode resolved to) and how many units. It is a list of
 * props, not stock: Prepare Training (App\Services\TrainingPreparer) reads it to decide which goods go to which station
 * and only then writes the stock that is meant to be on the tables already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(0);
            $table->foreignId('person_id_user')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_products');
    }
};
