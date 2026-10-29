<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Turnstile keys are instance-wide but each Cloudflare widget lists the hostnames it accepts (MULTI_WAREHOUSE.md §13). The first time a
// Turnstile check succeeds on a mapped host, Cloudflare names that host in its answer and we record it here: until then the host is
// "not confirmed" and registration, /join and /vote may fail on it.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('warehouse_domains', function (Blueprint $table) {
            $table->timestamp('turnstile_confirmed_at')->nullable()->after('skip_chooser');
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('warehouse_domains', function (Blueprint $table) {
            $table->dropColumn('turnstile_confirmed_at');
        });
    }
};
