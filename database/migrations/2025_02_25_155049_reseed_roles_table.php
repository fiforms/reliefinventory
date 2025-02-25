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
        DB::table('people_roles')->delete();
        DB::table('roles')->delete();
        
        // Seed initial roles
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Customer', 'description' => 'Person receiving relief supplies', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Donor', 'description' => 'Person or organization donating supplies', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Volunteer', 'description' => 'Person helping with relief efforts', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'name' => 'Team Leader', 'description' => 'A Volunteer with More Privileges', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 15, 'name' => 'Administrator', 'description' => 'Super User', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('people_roles')->delete();
        DB::table('roles')->delete();
    }
};
