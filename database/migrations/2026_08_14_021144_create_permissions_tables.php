<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Granular permissions model (granular-permissions-model.md), superseding
 * the role_bitpack magnitude check (CheckRole) as the route/action gating
 * mechanism — not a patch on it, a replacement. Roles stay meaningful named
 * bundles (role_permissions is what each bundle actually grants); a person's
 * effective access is their roles' defaults, with person_permissions as a
 * per-person override in either direction on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // slug, e.g. "manage-items"
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('person_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            // true = grants beyond the person's roles; false = explicitly
            // revokes a permission a role would otherwise grant.
            $table->boolean('granted');
            $table->timestamps();

            $table->unique(['person_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
