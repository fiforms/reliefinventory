<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable, admin-built survey/questionnaire definition — the generic
 * form-builder tool discussed 2026-08-30 (see form-builder-and-partner-
 * intake-design memory). Partner Agency Intake is the first real form built
 * on this, not a special case: everything partner-intake-specific lives in
 * the seeded row data (on_approval_action, requires_approval), not in this
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('intro_text')->nullable();
            // draft: not yet visible anywhere. active: reachable at
            // /forms/{slug} (subject to access_mode) and offered in staff
            // pickers. archived: kept for its historical submissions, no
            // longer reachable to submit new ones.
            $table->string('status')->default('draft');
            // public: anyone with the link, unauthenticated. staff_only:
            // requires login. both: works either way — same route, the
            // difference is purely who's allowed to load it.
            $table->string('access_mode')->default('staff_only');
            $table->boolean('requires_approval')->default(false);
            // 'none' or 'create_or_link_partner' today; extensible for a
            // future approval action without a schema change.
            $table->string('on_approval_action')->default('none');
            $table->json('notify_person_ids')->nullable();
            $table->text('notify_emails')->nullable();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
