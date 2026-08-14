<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            // A small warehouse running boxes-and-bins-only shouldn't have
            // pallet-specific UI/controller surface cluttering its workflow.
            $table->boolean('pallets_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('pallets_enabled');
        });
    }
};
