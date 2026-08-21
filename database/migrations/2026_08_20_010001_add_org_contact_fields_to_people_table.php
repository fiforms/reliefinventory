<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds org/contact modeling to Person, driven by the Flowtrac import
     * work: is_organization marks a Person as the org record itself (not
     * inferred from organization being set, since a contact row also
     * wants to record its own org affiliation for context);
     * parent_person_id is a self-referential link from a contact Person to
     * its org Person; contact_role is a free-text tag describing that
     * relationship (Primary/Delivery/Billing/...) — deliberately not a
     * governed lookup table, since real Flowtrac contact data showed role
     * flags going unused or being non-exclusive (multiple contacts under
     * one account simultaneously flagged "default"); category_id is the
     * open-ended party-type tag (Donor/Supplier/Warehouse Contact/...).
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('is_organization')->default(false)->after('organization');
            $table->unsignedBigInteger('parent_person_id')->nullable()->after('is_organization');
            $table->string('contact_role', 100)->nullable()->after('parent_person_id');
            $table->unsignedBigInteger('category_id')->nullable()->after('contact_role');

            $table->foreign('parent_person_id')->references('id')->on('people')->nullOnDelete();
            $table->foreign('category_id')->references('id')->on('person_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['parent_person_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['is_organization', 'parent_person_id', 'contact_role', 'category_id']);
        });
    }
};
