<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text driver_name/driver_phone pair with a real Driver
 * relation, so frequently-returning drivers can be looked up instead of
 * retyped. Existing rows are migrated into drivers records (deduped by
 * name+phone) before the old columns are dropped — this runs against a live
 * beta instance with real intake data, so the migration preserves it rather
 * than discarding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('driver_phone')->constrained('drivers')->nullOnDelete();
        });

        $seen = [];
        DB::table('orderdonations')->whereNotNull('driver_name')->orderBy('id')->each(function ($row) use (&$seen) {
            $key = strtolower(trim($row->driver_name)).'|'.strtolower(trim((string) $row->driver_phone));
            if (! isset($seen[$key])) {
                $seen[$key] = DB::table('drivers')->insertGetId([
                    'name' => trim($row->driver_name),
                    'phone' => $row->driver_phone ? trim($row->driver_phone) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('orderdonations')->where('id', $row->id)->update(['driver_id' => $seen[$key]]);
        });

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn(['driver_name', 'driver_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->string('driver_name')->nullable()->after('manifest_weight_lbs');
            $table->string('driver_phone')->nullable()->after('driver_name');
        });

        DB::table('orderdonations')
            ->join('drivers', 'drivers.id', '=', 'orderdonations.driver_id')
            ->update([
                'orderdonations.driver_name' => DB::raw('drivers.name'),
                'orderdonations.driver_phone' => DB::raw('drivers.phone'),
            ]);

        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
        });
    }
};
