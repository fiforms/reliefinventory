<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->cascadeOnDelete();
            // Set null (never cascade-deleted) so a submission's answers
            // stay readable even if the question is later removed from the
            // form — question_label_snapshot/question_type_snapshot below
            // keep them displayable either way.
            $table->foreignId('form_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question_label_snapshot');
            $table->string('question_type_snapshot');
            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_answers');
    }
};
