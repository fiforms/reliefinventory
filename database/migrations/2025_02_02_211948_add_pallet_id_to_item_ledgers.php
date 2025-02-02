<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->unsignedBigInteger('pallet_id')->nullable()->after('item_id');
            $table->foreign('pallet_id')->references('id')->on('pallets')->onDelete('set null');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('item_ledgers', function (Blueprint $table) {
            $table->dropForeign(['pallet_id']);
            $table->dropColumn('pallet_id');
        });
    }
};
