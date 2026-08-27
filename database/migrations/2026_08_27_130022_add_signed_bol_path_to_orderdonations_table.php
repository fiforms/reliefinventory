<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver's signed/scanned copy of the BOL, uploaded through the Driver
 * Portal (photo or PDF) once a load is delivered — closes the chain of
 * custody the printed BOL started. Stored the same way as Receiving's
 * photo_path (local disk, served through a permission-gated download
 * route), not reusing bol_number's slot since that's the generated form,
 * this is the returned proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('signed_bol_path')->nullable()->after('bol_number');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn('signed_bol_path');
        });
    }
};
