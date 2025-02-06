<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('packagetypes', function (Blueprint $table) {
            // Rename 'type' column to 'singular'
            $table->renameColumn('type', 'singular');

            // Add 'plural' column
            $table->string('plural')->nullable()->after('singular');

            // Drop old unique constraint and add a new one for both columns
            $table->dropUnique('packagetypes_type_unique');
            $table->unique(['singular', 'plural'], 'packagetypes_singular_plural_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packagetypes', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('packagetypes_singular_plural_unique');

            // Drop 'plural' column
            $table->dropColumn('plural');

            // Rename 'singular' back to 'type'
            $table->renameColumn('singular', 'type');

            // Restore the old unique constraint
            $table->unique('type', 'packagetypes_type_unique');
        });
    }
};
