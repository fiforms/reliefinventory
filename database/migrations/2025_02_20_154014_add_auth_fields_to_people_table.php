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
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('password', 255)->nullable()->after('email_verified_at');
            $table->string('remember_token', 100)->nullable()->after('password');
            $table->string('first_name', 255)->nullable()->change();
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'password', 'remember_token']);
            $table->string('first_name', 255)->nullable(false)->change();
        });
    }
};
