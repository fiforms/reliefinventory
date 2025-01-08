<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->change(); // Make person_id nullable for anonymous donations
            $table->enum('type', ['order', 'donation'])->after('id'); // Add a new column to distinguish between orders and donations
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('type'); // Remove the type column
            $table->foreignId('person_id')->nullable(false)->change(); // Revert person_id to non-nullable
        });
    }
};

