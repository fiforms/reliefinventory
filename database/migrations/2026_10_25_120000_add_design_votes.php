<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design Votes (App\Support\DesignPolls): people try live demos of a few UX designs at /vote/{slug} and vote for one.
 * The polls themselves are in code; design_polls only holds whether each is open, design_poll_votes one vote per
 * browser (a hashed cookie token). Adds manage-design-votes (Office + Administrator), the Setup menu tile, and the
 * first poll, delivery-days, closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_polls', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->boolean('is_open')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('design_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->string('poll_slug', 60);
            $table->string('option_key', 60);
            $table->string('voter_token_hash', 64);
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('voter_name', 100)->nullable();
            $table->string('voter_role', 30)->nullable();
            $table->text('comment')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->unique(['poll_slug', 'voter_token_hash']);
            $table->index(['poll_slug', 'option_key']);
        });

        DB::table('design_polls')->insert(['slug' => 'delivery-days', 'is_open' => false, 'created_at' => now(), 'updated_at' => now()]);

        $permissionId = DB::table('permissions')->where('key', 'manage-design-votes')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'key' => 'manage-design-votes',
                'name' => 'manage-design-votes',
                'description' => 'Open and close Design Votes polls and see their results',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        foreach (['Office', 'Administrator'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $setup = DB::table('pages')->where('hashtag', 'setup')->value('id');
        if ($setup && ! DB::table('menu_items')->where('link_url', '/setup/design-votes')->exists()) {
            DB::table('menu_items')->insert([
                'page_id' => $setup, 'link_text' => 'Design Votes', 'link_url' => '/setup/design-votes',
                'graphic_url' => '/img/printing-reports.webp', 'order' => 83, 'permission_key' => 'manage-design-votes',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menu_items')->where('link_url', '/setup/design-votes')->delete();
        DB::table('permissions')->where('key', 'manage-design-votes')->delete();
        Schema::dropIfExists('design_poll_votes');
        Schema::dropIfExists('design_polls');
    }
};
