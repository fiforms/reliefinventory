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
            // Create the "uses" table
            Schema::create('uses', function (Blueprint $table) {
                $table->id();
                $table->string('use')->unique();
                $table->timestamps();
            });
            
            // Insert predefined use types
            DB::table('uses')->insert([
                ['use' => 'Staging', 'created_at' => now(), 'updated_at' => now()],
                ['use' => 'Long-Term Storage', 'created_at' => now(), 'updated_at' => now()],
                ['use' => 'Sorting', 'created_at' => now(), 'updated_at' => now()],
            ]);
            
            // Modify the "locations" table
            Schema::table('locations', function (Blueprint $table) {
                $table->integer('PullSequence')->after('id');
                $table->string('Route')->after('PullSequence');
                $table->string('Block')->after('Route');
                $table->string('Aisle')->after('Block');
                $table->string('Lane')->after('Aisle');
                $table->string('Stack')->after('Lane');
                $table->unsignedBigInteger('use_id')->nullable()->after('Stack');
                
                $table->foreign('use_id')->references('id')->on('uses')->onDelete('set null');
            });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Drop the foreign key and column in "locations"
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['use_id']);
            $table->dropColumn([
                'PullSequence',
                'Route',
                'Block',
                'Aisle',
                'Lane',
                'Stack',
                'use_id'
            ]);
        });
            
            // Drop the "uses" table
            Schema::dropIfExists('uses');
    }
};
