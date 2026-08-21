<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single reference photo of the shipment/load, captured at intake — the
 * real MachForm Manifest form had this and it proved genuinely useful.
 * Stored the same way as FeedbackReport screenshots (local disk, path only
 * here, served through a guarded controller action).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('comments');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
