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
        Schema::create('form_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->string('label');
            $table->text('help_text')->nullable();
            // short_text, long_text, number, date, yes_no, single_choice,
            // multiple_choice, section_header (a non-answerable divider).
            $table->string('type');
            // Choice list for single_choice/multiple_choice.
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            // Which FormQuestionPreset this was added from, if any — purely
            // provenance (a page reload of the preset picker knows what's
            // already been added), never a live binding back to the preset.
            $table->string('preset_key')->nullable();
            // Maps this question's answer onto a real people.* column
            // (organization/address/city/state/zip/phone/email) so approval
            // can pre-fill a new/linked Person from it. Null for anything
            // that doesn't correspond to an existing column (website,
            // 501(c)(3) status, families served/week, ...) — those stay
            // display-only on the review screen.
            $table->string('target_field')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_questions');
    }
};
