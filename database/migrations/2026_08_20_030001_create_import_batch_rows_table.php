<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-row audit/progress/error detail for one import_batches row — what
     * the /setup/import "show errors" drill-in reads. Written on both
     * Preview and Commit passes (outcome distinguishes a dry-run proposal
     * from an actual write via the batch's own status at write time).
     */
    public function up(): void
    {
        Schema::create('import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_key')->nullable(); // natural key derived from the row
            $table->enum('outcome', ['created', 'updated', 'skipped', 'error'])->nullable();
            $table->text('error_message')->nullable();
            $table->json('raw_row')->nullable();
            $table->string('mapped_entity_type')->nullable();
            $table->unsignedBigInteger('mapped_entity_id')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_rows');
    }
};
