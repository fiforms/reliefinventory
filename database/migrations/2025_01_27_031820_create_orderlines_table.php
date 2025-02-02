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
        Schema::create('orderlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orderdonation_id')->constrained('orderdonations')->onDelete('cascade');
            $table->foreignId('itemtype_id')->constrained('itemtypes')->onDelete('restrict');
            $table->foreignId('packagetype_id')->constrained('packagetypes')->onDelete('restrict');
            $table->integer('qty_requested');
            $table->text('comments')->nullable();  
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orderlines');
    }
};
