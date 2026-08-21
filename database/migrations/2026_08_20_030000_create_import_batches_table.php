<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per upload. Preview never writes app data (people/items/
     * transactions) — only Commit does, and only after a Preview has run
     * on that same batch. `summary` holds the created/updated/skipped/
     * errored counts the /setup/import UI reads without re-scanning rows.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // e.g. "flowtrac"
            $table->string('file_type'); // e.g. "contacts" | "products" | "donations_received"
            $table->string('original_filename');
            $table->string('stored_path'); // Storage::disk('local') relative path
            $table->enum('status', ['previewed', 'committing', 'completed', 'failed'])->default('previewed');
            $table->json('summary')->nullable(); // {created, updated, skipped, errored}
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
