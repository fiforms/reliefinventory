<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional per-pallet contents captured at Receiving: a free-text description
// ("Mixed pallet") and/or a specific item for single-item pallets (a whole
// pallet of one product). The item tag is prep for expedited sorting — a
// pallet known to be all one item can skip line-by-line sorting and just be
// counted and put away.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->string('content_description')->nullable()->after('orderdonation_id');
            $table->foreignId('content_item_id')->nullable()->after('content_description')
                ->constrained('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_item_id');
            $table->dropColumn('content_description');
        });
    }
};
