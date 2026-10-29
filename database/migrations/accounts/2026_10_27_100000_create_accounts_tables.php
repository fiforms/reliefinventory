<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Accounts schema (MULTI_WAREHOUSE.md §6): login credentials shared by every warehouse, and the only
// record of who belongs where. Run by `php artisan instance:migrate`.
return new class extends Migration
{
    protected $connection = 'accounts';

    public function up(): void
    {
        $schema = Schema::connection('accounts');

        $schema->create('accounts', function (Blueprint $table) {
            $table->id();
            // utf8mb4_unicode_ci: the unique index is case-insensitive.
            $table->string('email')->unique();
            $table->string('password')->nullable();   // null: invited, hasn't chosen one yet
            $table->timestamp('email_verified_at')->nullable();
            // Account-level lock (every warehouse); a membership or person can also be disabled locally.
            $table->timestamp('locked_at')->nullable();
            // Settable only from the instance console or CLI, never from a warehouse or a backup.
            $table->boolean('is_instance_admin')->default(false);
            // Bumped on lock, password change and reset; a session started under an older value is ended.
            $table->unsignedInteger('auth_epoch')->default(0);
            $table->timestamps();
        });

        $schema->create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->char('warehouse_id', 8);          // control schema: no foreign key across schemas
            $table->unsignedBigInteger('person_id');  // that warehouse's people.id
            $table->string('status', 20)->default('active');
            // Copied from the warehouse: training memberships don't count for the sole-membership rule.
            $table->boolean('is_training')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'warehouse_id']);
            $table->unique(['warehouse_id', 'person_id']);
        });

        // Here, not in a warehouse schema, so a restored dump can't plant a reset token (§5).
        $schema->create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // The `accounts` cache store: login attempt counts per account, shared by every warehouse (§6).
        $schema->create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });
        $schema->create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        foreach (['cache_locks', 'cache', 'password_reset_tokens', 'memberships', 'accounts'] as $table) {
            Schema::connection('accounts')->dropIfExists($table);
        }
    }
};
