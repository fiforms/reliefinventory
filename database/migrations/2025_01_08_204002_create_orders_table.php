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
        Schema::create('orders', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->foreignId('person_id')->constrained('people')->onDelete('cascade'); // Link to 'people' table
            $table->foreignId('status_id')->constrained('statuses')->onDelete('restrict'); // Link to 'statuses' table
            $table->foreignId('user_id')->constrained('users'); // Link to Laravel users table (nullable if no user is associated)
            $table->date('order_date'); // Date of the order
            $table->text('comments')->nullable(); // Comments field (optional)
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

