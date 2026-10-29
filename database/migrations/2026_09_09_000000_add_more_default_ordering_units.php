<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * the Default Ordering Unit work: the "Default Ordering Unit" list (units table) only had
 * weight/volume/each measurement units — no way to order something by the
 * container it actually ships in. insertOrIgnore (not the seeder, which
 * only runs once per instance at initial setup) so this lands on every
 * existing instance too, not just fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('units')->insertOrIgnore([
            ['abbreviation' => 'case', 'name' => 'case', 'type' => 'other'],
            ['abbreviation' => 'bag', 'name' => 'bag', 'type' => 'other'],
            ['abbreviation' => 'pallet', 'name' => 'pallet', 'type' => 'other'],
            ['abbreviation' => 'jug', 'name' => 'jug', 'type' => 'other'],
        ]);
    }

    public function down(): void
    {
        DB::table('units')->whereIn('abbreviation', ['case', 'bag', 'pallet', 'jug'])->delete();
    }
};
