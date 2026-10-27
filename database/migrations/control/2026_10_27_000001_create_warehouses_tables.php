<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Control schema (MULTI_WAREHOUSE.md §2): the warehouses this instance hosts and the hostnames that reach
// them. Run by `php artisan instance:migrate`, never by a plain `migrate` (which only sees a warehouse schema).
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('warehouses', function (Blueprint $table) {
            // Random, opaque, never reused (App\Models\Control\Warehouse::newId()).
            $table->char('id', 8)->primary();
            $table->uuid('uuid')->unique();
            // Stored, never derived: the default warehouse keeps the schema it always had.
            $table->string('schema_name', 64)->unique();
            $table->string('display_name')->unique();
            $table->string('admin_email')->nullable();
            $table->string('status', 20)->default('active');
            // The warehouse unprefixed machine URLs reach (§4); exactly one row has it.
            $table->boolean('is_default')->default(false);
            $table->boolean('listed')->default(true);
            $table->boolean('is_training')->default(false);
            $table->string('migration_state', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::connection('control')->create('warehouse_domains', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->unique();   // lower case, no port
            $table->char('warehouse_id', 8);
            $table->boolean('is_primary')->default(false);
            $table->boolean('skip_chooser')->default(true);
            $table->timestamps();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('warehouse_domains');
        Schema::connection('control')->dropIfExists('warehouses');
    }
};
