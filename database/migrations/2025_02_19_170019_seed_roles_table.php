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
        // Seed initial roles
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            ['name' => 'Customer', 'description' => 'Person receiving relief supplies', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Donor', 'description' => 'Person or organization donating supplies', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Volunteer', 'description' => 'Person helping with relief efforts', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')
        ->whereIn('name', ['Customer', 'Donor', 'Volunteer'])
        ->delete();
    }
};
