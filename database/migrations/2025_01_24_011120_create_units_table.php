<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('abbreviation')->unique();
            $table->string('name')->unique();
            $table->enum('type',['weight', 'volume','each','other']);
            
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
