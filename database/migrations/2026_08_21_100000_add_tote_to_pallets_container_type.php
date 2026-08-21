<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Matches the new "Tote" option on Receiving's top-level "How did this
 * arrive?" question (see the 2026_08_21_110000 migration) — a load that
 * arrived in totes should be taggable as totes during sorting too, not
 * forced into "box".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pallets MODIFY COLUMN container_type ENUM('pallet', 'gaylord', 'box', 'bag', 'tote') NOT NULL DEFAULT 'pallet'");
    }

    public function down(): void
    {
        DB::statement("UPDATE pallets SET container_type = 'pallet' WHERE container_type = 'tote'");
        DB::statement("ALTER TABLE pallets MODIFY COLUMN container_type ENUM('pallet', 'gaylord', 'box', 'bag') NOT NULL DEFAULT 'pallet'");
    }
};
