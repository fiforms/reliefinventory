<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federation (see App\Services\Federation) replaced three older mechanisms, which are removed here:
 *   - the feedback shared secret and the central ingest URL typed beside it (a remote now names the trust it sends
 *     reports through, `feedback_trust_id`), and the self-asserted `instance_identifier` setting (central derives
 *     the source from the trust);
 *   - the 6-digit device pairing codes (devices pair with a trust pairing code and their own key);
 *   - the bearer-token hash on `managed_devices` (a device authenticates as a trust).
 * Anything still holding a pre-federation token or secret must pair again; down() restores empty columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_aggregation_settings', function (Blueprint $table) {
            $table->dropColumn(['instance_identifier', 'central_ingest_url', 'shared_secret']);
        });
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
        Schema::dropIfExists('device_pairing_codes');
    }

    public function down(): void
    {
        Schema::create('device_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->foreignId('kiosk_location_id')->constrained('kiosk_locations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('managed_device_id')->nullable()->constrained('managed_devices')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('managed_devices', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->unique();
        });
        Schema::table('feedback_aggregation_settings', function (Blueprint $table) {
            $table->string('instance_identifier')->nullable();
            $table->string('central_ingest_url')->nullable();
            $table->text('shared_secret')->nullable();
        });
    }
};
