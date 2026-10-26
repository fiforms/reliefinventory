<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This instance's own Ed25519 identity for federation (see App\Services\Federation). One row today;
 * `tenant_id` is reserved so a future multi-tenant instance can hold one identity per tenant. The key pair
 * is generated lazily by InstanceIdentity::current(), not here, so a fresh install and a restored one
 * behave the same. `private_key` is APP_KEY-encrypted and listed in config/backup.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->unique();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('public_key', 64);
            $table->text('private_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_identities');
    }
};
