<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trust registry (see App\Services\Federation). A `trusts` row is this instance's local view of one
 * peer — another instance, or a client app such as the print client — identified by the peer's own uuid and
 * Ed25519 public key. `trust_grants` holds what the peer may do to us (inbound) and what we may do to it
 * (outbound, mirrored from the peer). `approved_by_label` exists beside `approved_by_person_id` so a later
 * self-service method (an account on a central instance) can name its approver without a local person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('identity_id')->constrained('federation_identities')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('peer_uuid', 64);
            $table->string('name');
            $table->string('public_key', 64);
            $table->string('base_url')->nullable();
            $table->string('status', 16)->default('approved');
            // Bumped on every (re-)approval and revoke; a session token carries the epoch it was issued under.
            $table->unsignedInteger('session_epoch')->default(0);
            $table->string('auth_method', 32);
            $table->foreignId('approved_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('approved_by_label')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('domain_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 64)->nullable();
            $table->timestamps();
            $table->unique(['identity_id', 'peer_uuid']);
        });

        Schema::create('trust_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_id')->constrained('trusts')->cascadeOnDelete();
            $table->string('permission', 48);
            $table->string('direction', 8);
            $table->timestamps();
            $table->unique(['trust_id', 'permission', 'direction']);
        });

        Schema::create('trust_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->string('kind', 32);
            $table->string('name_hint')->nullable();
            $table->json('grants');
            $table->foreignId('kiosk_location_id')->nullable()->constrained('kiosk_locations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('trust_id')->nullable()->constrained('trusts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('trust_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_id')->nullable()->constrained('trusts')->nullOnDelete();
            $table->string('event', 48);
            $table->foreignId('actor_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('managed_devices', function (Blueprint $table) {
            $table->foreignId('trust_id')->nullable()->after('id')->constrained('trusts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trust_id');
        });
        Schema::dropIfExists('trust_audit_log');
        Schema::dropIfExists('trust_pairing_codes');
        Schema::dropIfExists('trust_grants');
        Schema::dropIfExists('trusts');
    }
};
