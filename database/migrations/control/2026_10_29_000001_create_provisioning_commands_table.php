<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plain commands for the provisioner (MULTI_WAREHOUSE.md §7), not serialized Laravel jobs: the web container can write
// this table, and the process that holds the database administrator credential must never unserialize anything from
// it. The provisioner re-checks every command against the `warehouses` row; what is written here is only a request.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('provisioning_commands', function (Blueprint $table) {
            $table->id();
            $table->char('warehouse_id', 8);
            $table->string('command', 20);
            $table->string('status', 10)->default('pending')->index();   // pending | running | done | failed
            $table->string('requested_by')->nullable();                   // a label for the audit trail, never trusted
            $table->text('message')->nullable();                          // what was done, or why it failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('provisioning_commands');
    }
};
