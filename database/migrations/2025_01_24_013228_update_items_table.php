<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add new columns to the `items` table
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('itemtype_id')->nullable()->after('id');
            $table->string('pluscode', 4)->after('itemtype_id');
            $table->unsignedBigInteger('packagetypes_id')->after('itemtype_id');
            $table->string('upc', 50)->after('description')->nullable()->unique();
            $table->decimal('size', 8, 2)->nullable()->change();
            
        });
            
            // Find the first valid category_id
            $categoryId = DB::table('categories')->value('id');
            
            if ($categoryId) {
                // Insert a default "Unknown" itemtype into `itemtypes`
                $itemtypeId = DB::table('itemtypes')->insertGetId([
                    'item_number' => '0000',
                    'category_id' => $categoryId,
                    'itemtype_name' => 'Unknown',
                    'itemtype_desc' => 'Default item type for unlinked items',
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                //  Set all existing items to use the "Unknown" itemtype
                DB::table('items')->update(['itemtype_id' => $itemtypeId]);
                
                $packagetypeId = DB::table('packagetypes')->insertGetId([
                     'type' => 'Each', 
                ]);
                DB::table('items')->update(['packagetypes_id' => $packagetypeId]);
                
                
            } else {
                // Log a message if no valid category_id exists
                info('No valid category_id found in the categories table. Skipping insertion of "Unknown" itemtype.');
            }
            
            // Drop foreign key constraints and columns
            Schema::table('items', function (Blueprint $table) {
                // Drop foreign key constraint for category_id if it exists
                $table->dropForeign('items_category_id_foreign');
                
                // Drop the old columns
                $table->dropColumn(['item_number', 'category_id', 'counted_by']);
                
                // Add the foreign key for itemtype_id
                $table->foreign('itemtype_id')->references('id')->on('itemtypes')->onDelete('cascade');
                $table->foreign('packagetypes_id')->references('id')->on('packagetypes')->onDelete('restrict');
            });
    }
    
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Re-add the dropped columns
            $table->string('item_number')->after('id');
            $table->unsignedBigInteger('category_id')->after('item_number');
            $table->enum('counted_by', ['each', 'case'])->after('category_id'); 
            
            // Remove the new columns and foreign key
            $table->dropForeign(['itemtype_id']);
            $table->dropForeign(['packagetypes_id']);
            $table->dropColumn(['pluscode', 'itemtype_id','packagetypes_id','upc']);
        });
            
    }
};
 