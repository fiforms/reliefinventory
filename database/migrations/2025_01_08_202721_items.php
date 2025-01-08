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
        Schema::create('items', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('item_number')->unique(); // User-assigned item number
            $table->foreignId('category_id')->constrained('categories')->onDelete('cascade'); // Foreign key to categories table
            $table->enum('counted_by', ['each', 'case']); // Indicates if the item is counted by "Each" or "Case"
	    $table->decimal('size', 8, 2); // Size as a decimal (e.g., 1.25)
            $table->integer('case_qty')->nullable(); // Quantity in a case
            $table->text('description')->nullable(); // Description
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
