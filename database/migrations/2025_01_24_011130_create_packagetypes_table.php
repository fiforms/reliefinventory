<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('packagetypes', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('packagetypes');
    }
};
