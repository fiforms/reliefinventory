<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The one-time code that hands a new, empty warehouse to whoever will run it (MULTI_WAREHOUSE.md §7). Only the hash is kept.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('warehouse_setup_codes', function (Blueprint $table) {
            $table->id();
            $table->char('warehouse_id', 8);
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by')->nullable();   // a label for the audit trail
            $table->timestamps();

            $table->index(['warehouse_id', 'code_hash']);
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('warehouse_setup_codes');
    }
};
