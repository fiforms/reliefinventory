<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person to contact about *this specific shipment* — distinct from
 * person_id (the donor/org itself). Reuses the org-contact model built for
 * People (is_organization/parent_person_id/contact_role) rather than a
 * free-text name/phone pair, so a contact picked here is a real, reusable
 * Person the next shipment from the same org can pick again. See
 * person-tagging-and-org-contacts-design memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->foreignId('contact_person_id')->nullable()->after('person_id')->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_person_id');
        });
    }
};
