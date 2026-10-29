<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton settings row (always id=1): which media (config/printing.php) pallet labels are laid
     * out for and printed on. Defaults to the original 4 x 6.5 in label, so nothing changes for an
     * existing instance until an administrator picks another size.
     */
    public function up(): void
    {
        Schema::create('label_settings', function (Blueprint $table) {
            $table->id();
            $table->string('pallet_label_media', 40)->default('label_large');
            $table->timestamps();
        });

        DB::table('label_settings')->insert([
            'id' => 1,
            'pallet_label_media' => 'label_large',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('label_settings');
    }
};
