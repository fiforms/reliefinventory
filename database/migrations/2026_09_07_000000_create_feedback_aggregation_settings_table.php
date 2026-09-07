<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton settings row (always id=1), same pattern as
     * PinLoginSetting/BannerSetting — replaces the FEEDBACK_INSTANCE_ROLE /
     * FEEDBACK_INSTANCE_IDENTIFIER / FEEDBACK_CENTRAL_INGEST_URL /
     * FEEDBACK_SHARED_SECRET env vars (config/feedback.php) with an
     * admin-editable settings panel on /setup/feedback, gated the same as
     * everything else there (manage-feedback). `shared_secret` is stored
     * via Eloquent's `encrypted` cast rather than plaintext.
     *
     * Backfills from whatever those env vars are currently set to, so an
     * instance already configured for aggregation before this migration
     * keeps working after it runs.
     */
    public function up(): void
    {
        Schema::create('feedback_aggregation_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('instance_role', ['remote', 'central'])->default('remote');
            $table->string('instance_identifier')->nullable();
            $table->string('central_ingest_url')->nullable();
            $table->text('shared_secret')->nullable();
            $table->timestamps();
        });

        $sharedSecret = env('FEEDBACK_SHARED_SECRET');

        DB::table('feedback_aggregation_settings')->insert([
            'id' => 1,
            'instance_role' => env('FEEDBACK_INSTANCE_ROLE', 'remote') === 'central' ? 'central' : 'remote',
            'instance_identifier' => env('FEEDBACK_INSTANCE_IDENTIFIER'),
            'central_ingest_url' => env('FEEDBACK_CENTRAL_INGEST_URL'),
            'shared_secret' => filled($sharedSecret) ? encrypt($sharedSecret) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_aggregation_settings');
    }
};
