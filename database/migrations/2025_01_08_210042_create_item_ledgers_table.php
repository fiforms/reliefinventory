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
        Schema::create('item_ledgers', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->foreignId('orderdonation_id')->constrained('orderdonations')->onDelete('cascade'); // Links to orderdonations
            $table->foreignId('item_id')->constrained('items')->onDelete('restrict'); // Prevent deletion if the item is referenced
            $table->integer('qty_added')->default(0); // Quantity added to inventory
            $table->integer('qty_subtracted')->default(0); // Quantity subtracted from inventory
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_ledgers');
    }
};

