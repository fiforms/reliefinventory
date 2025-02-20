<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Check if the foreign key exists and drop it
        $foreignKey = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'orderdonations'
            AND COLUMN_NAME = 'user_id'
        ");
        
        if (!empty($foreignKey)) {
            $constraintName = $foreignKey[0]->CONSTRAINT_NAME;
            Schema::table('orderdonations', function (Blueprint $table) use ($constraintName) {
                $table->dropForeign($constraintName);
            });
        }
            
            // Step 2: Add the new column person_id_user to link to people table
            Schema::table('orderdonations', function (Blueprint $table) {
                $table->unsignedBigInteger('person_id_user')->nullable()->after('user_id');
            });
                
                // Step 3: Populate person_id_user by matching users.name -> people.last_name
                DB::statement("
            UPDATE orderdonations od
            JOIN users u ON od.user_id = u.id
            JOIN people p ON u.email = p.email
            SET od.person_id_user = p.id
        ");
                
                // Step 4: Drop the user_id column
                Schema::table('orderdonations', function (Blueprint $table) {
                    $table->dropColumn('user_id');
                });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Step 1: Add back the user_id column
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
        });
            
            // Step 2: Restore user_id values by matching people.last_name back to users.name
            DB::statement("
            UPDATE orderdonations od
            JOIN people p ON od.person_id_user = p.id
            JOIN users u ON p.email = u.email
            SET od.user_id = u.id
        ");
            
            // Step 3: Drop the new person_id_user column
            Schema::table('orderdonations', function (Blueprint $table) {
                $table->dropColumn('person_id_user');
            });
                
                // Step 4: Restore the foreign key constraint on user_id
                Schema::table('orderdonations', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                });
    }
};
