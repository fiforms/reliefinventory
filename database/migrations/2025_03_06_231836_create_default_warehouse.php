<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        // Step 1: Create Default Warehouse
        $defaultWarehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Default Warehouse',
            'address' => '123 No Street',
            'city' => 'Nowhere',
            'state' => 'NC',
            'zip' => '00000',
            'county_id' => null, // Update this if county logic applies
            'manager_id' => null, // Update this if needed
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Step 2: Update locations table, setting null warehouse_ids to the default warehouse
        DB::table('locations')->whereNull('warehouse_id')->update([
            'warehouse_id' => $defaultWarehouseId
        ]);
        
        // Step 3: Drop foreign key constraint temporarily
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
        });
            
        // Step 4: Modify warehouse_id column to be NOT NULL
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();
        });
            
        // Step 5: Re-add foreign key constraint
        Schema::table('locations', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('cascade');
        });
    }
    
    public function down()
    {
        // Step 1: Find the Default Warehouse ID (if exists)
        $defaultWarehouse = DB::table('warehouses')->where('name', 'Default Warehouse')->first();
        
        if ($defaultWarehouse) {
            
            // Step 2: Drop foreign key constraint before making warehouse_id nullable
            Schema::table('locations', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
            });
                
            // Step 3: Make warehouse_id nullable again
            Schema::table('locations', function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->change();
            });
            
            // Step 4: Reset locations warehouse_id to NULL before deleting warehouse
            DB::table('locations')->where('warehouse_id', $defaultWarehouse->id)->update([
                'warehouse_id' => null
            ]);
                
            // Step 5: Delete the Default Warehouse
            DB::table('warehouses')->where('id', $defaultWarehouse->id)->delete();
            
            // Step 6: Re-add foreign key constraint
            Schema::table('locations', function (Blueprint $table) {
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('set null');
            });
        }
    }
};
