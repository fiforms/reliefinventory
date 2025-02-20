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
        // Step 1: Update existing people records if the email already exists
        DB::statement("
            UPDATE people p
            JOIN users u ON p.email = u.email
            SET
                p.password = u.password,
                p.email_verified_at = u.email_verified_at,
                p.remember_token = u.remember_token,
                p.updated_at = u.updated_at
        ");
        
        // Step 2: Insert new people records for users that don't exist in people
        DB::statement("
            INSERT INTO people (last_name, email, email_verified_at, password, remember_token, created_at, updated_at)
            SELECT u.name, u.email, u.email_verified_at, u.password, u.remember_token, u.created_at, u.updated_at
            FROM users u
            WHERE NOT EXISTS (
                SELECT 1 FROM people p WHERE p.email = u.email
            )
        ");
        
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Step 1: Restore only users who have a password
        DB::statement("
            INSERT INTO users (name, email, email_verified_at, password, remember_token, created_at, updated_at)
            SELECT last_name, email, email_verified_at, password, remember_token, created_at, updated_at
            FROM people
            WHERE password IS NOT NULL
        ");
        
        
        // Step 2: Remove any added people records that were created during the migration
        DB::statement("
            DELETE FROM people
            WHERE email IN (SELECT email FROM users)
            AND password IS NOT NULL
        ");
    }
};
