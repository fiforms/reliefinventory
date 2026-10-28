<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A request to the provisioner that is about the whole instance (`instance-backup`) names no warehouse.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('provisioning_commands', function (Blueprint $table) {
            $table->char('warehouse_id', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('provisioning_commands', function (Blueprint $table) {
            $table->char('warehouse_id', 8)->nullable(false)->change();
        });
    }
};
