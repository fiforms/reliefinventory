<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text follow-up fields for the two Receiving "Other" options
 * (category and arrival_method), so picking Other doesn't lose information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('category_other')->nullable()->after('category');
            $table->string('arrival_method_other')->nullable()->after('arrival_method');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn(['category_other', 'arrival_method_other']);
        });
    }
};
