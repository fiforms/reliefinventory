<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        // Modify the `palletstatus` table's `status` field to include new ENUM values
        DB::statement("ALTER TABLE palletstatus MODIFY COLUMN status ENUM('created', 'wrapped', 'moved', 'unwrapped', 'archived') NOT NULL");
        
        // Add new columns `last_location_id` and `last_status` to the `pallets` table
        Schema::table('pallets', function (Blueprint $table) {
            $table->unsignedBigInteger('last_location_id')->nullable()->after('updated_at');
            $table->enum('last_status', ['created', 'wrapped', 'moved', 'unwrapped', 'archived'])->nullable()->after('last_location_id');
            
            // Add foreign key constraint for `last_location_id`
            $table->foreign('last_location_id')->references('id')->on('locations')->onDelete('set null');
        });
            
            // Populate `last_location_id` and `last_status` in `pallets` table based on the latest palletstatus entry
            DB::statement("
            UPDATE pallets p
            JOIN (
                SELECT pallet_id, location_id, status
                FROM palletstatus
                WHERE id IN (SELECT MAX(id) FROM palletstatus GROUP BY pallet_id)
            ) ps ON p.id = ps.pallet_id
            SET p.last_location_id = ps.location_id, p.last_status = ps.status
        ");
    }
    
    public function down()
    {
        // Revert the `status` ENUM field modification
        DB::statement("ALTER TABLE palletstatus MODIFY COLUMN status ENUM('created', 'moved', 'archived') NOT NULL");
        
        // Remove columns from `pallets` table
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropForeign(['last_location_id']);
            $table->dropColumn(['last_location_id', 'last_status']);
        });
    }
};
