<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-person, per-device trust window: granted (and refreshed) every
     * time that person completes a real email+password login on an
     * approved device. This is the second, narrower gate PIN unlock checks
     * — device approval (trusted_devices.status) says "this device may
     * offer PIN unlock at all"; a grant here says "this specific person
     * has actually proven themselves on it recently." expires_at is null
     * for the 'indefinite' trust mode, computed at grant time otherwise
     * (see PinLoginSetting::trustMode()).
     */
    public function up(): void
    {
        Schema::create('device_trust_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trusted_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['trusted_device_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_trust_grants');
    }
};
