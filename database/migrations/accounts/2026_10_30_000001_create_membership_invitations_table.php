<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An offer to attach an existing login to a person in a warehouse (MULTI_WAREHOUSE.md §6, "invitations"). Nothing about the
// login changes until its owner accepts. Here, not in a warehouse schema, so a restored dump can't plant or accept one.
return new class extends Migration
{
    protected $connection = 'accounts';

    public function up(): void
    {
        Schema::connection('accounts')->create('membership_invitations', function (Blueprint $table) {
            $table->id();
            $table->char('warehouse_id', 8);              // control schema: no foreign key across schemas
            $table->unsignedBigInteger('person_id');      // that warehouse's people.id the login would become
            $table->string('email');                       // the login invited (utf8mb4_unicode_ci: case-insensitive)
            $table->char('token_hash', 64)->nullable();   // only for an emailed link; an invitation without one is accepted in the app
            $table->string('status', 20)->default('pending');   // pending | accepted | declined | withdrawn
            $table->string('source', 20);                  // admin | restore | import
            $table->string('invited_by')->nullable();      // a label: the inviting person's name, never trusted for anything
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'person_id']);
            $table->index(['email', 'status']);
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::connection('accounts')->dropIfExists('membership_invitations');
    }
};
