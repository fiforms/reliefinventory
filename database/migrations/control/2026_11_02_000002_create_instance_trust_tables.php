<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Instance-level trust: a computer (the RI Launcher) that may make and download WHOLE-INSTANCE backups. Everything else in federation
// (trusts, grants, pairing codes) is per warehouse, in each warehouse's schema; this is the one thing that belongs to the instance, so
// it is paired and revoked by an instance administrator and does not depend on any one warehouse. See App\Models\Control\InstanceTrust.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('instance_trusts', function (Blueprint $table) {
            $table->id();
            $table->string('peer_uuid', 64)->unique();
            $table->string('name');
            $table->string('public_key', 43);                    // Ed25519, base64url — what the computer proved it holds when pairing
            $table->string('status', 20)->default('approved');   // approved | revoked
            $table->json('permissions');                         // what it may do: App\Support\InstancePermission keys
            $table->unsignedInteger('session_epoch')->default(1);   // bumped on re-pairing and revoking: old session tokens stop matching
            $table->string('approved_by')->nullable();           // the instance administrator's email
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 64)->nullable();
            $table->timestamps();
        });

        Schema::connection('control')->create('instance_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();             // the code itself is shown once and never stored
            $table->json('permissions');
            $table->string('name_hint')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('trust_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('instance_pairing_codes');
        Schema::connection('control')->dropIfExists('instance_trusts');
    }
};
