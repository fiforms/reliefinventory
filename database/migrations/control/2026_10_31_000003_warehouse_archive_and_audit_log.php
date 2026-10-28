<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Removing a warehouse (MULTI_WAREHOUSE.md §6): archiving is the undoable step, so it remembers what the warehouse was; and the
// instance keeps its own audit log (who archived, restored or removed which warehouse, and which logins went with it).
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('warehouses', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->string('status_before_archive', 20)->nullable();
        });

        Schema::connection('control')->create('instance_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('actor');                           // a label: the command line, later the instance admin's login
            $table->string('action', 60)->index();             // warehouse.created, warehouse.archived, warehouse.removed ...
            $table->char('warehouse_id', 8)->nullable()->index();   // no foreign key: the row outlives the warehouse
            $table->string('subject')->nullable();             // the warehouse's name then, since its row may be gone
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('instance_audit_log');
        Schema::connection('control')->table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'status_before_archive']);
        });
    }
};
