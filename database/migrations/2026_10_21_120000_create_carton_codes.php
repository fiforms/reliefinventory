<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shipping carton's barcode -> the single-unit item row it contains, and how
 * many units one carton holds. A carton is not a separate product: scanning it
 * records the *contained* item (so cartons and single scans share one row and
 * can be combined), with the quantity filled in. `code` is the 14-digit
 * normalized GTIN (App\Helpers\Gtin). Only person-confirmed rows exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carton_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 14)->unique();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('qty_per_carton'); // total single units in one carton
            $table->unsignedInteger('inner_packs')->nullable(); // e.g. 6 portion packs...
            $table->unsignedInteger('items_per_pack')->nullable(); // ...of 5 items each
            $table->unsignedBigInteger('person_id_user')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_codes');
    }
};
