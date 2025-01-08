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
        Schema::create('people', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('first_name'); // First name
            $table->string('last_name'); // Last name
            $table->string('organization')->nullable(); // Organization or company (optional)
            $table->string('phone')->nullable(); // Phone number
            $table->string('email')->unique()->nullable(); // Email address (optional but unique if provided)
            $table->text('address')->nullable(); // Street address (optional)
            $table->string('city')->nullable(); // City (optional)
            $table->string('state', 2)->nullable(); // State (optional, 2-character code for U.S. states)
            $table->string('zip', 10)->nullable(); // ZIP code (optional, supports extended ZIP+4 format)
            $table->enum('type', ['customer', 'donor']); // Specifies whether the person is a customer or donor
            $table->text('comments')->nullable(); // Additional comments (optional)
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};

