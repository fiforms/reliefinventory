<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A loose box/bag arrival still needs a printable label and a trackable
 * unit even when the warehouse hasn't put it on a pallet or gaylord yet
 * (or never will). Widening the existing container_type enum lets the
 * pallet label PDF and pallet lifecycle machinery work unchanged for
 * these units — labels already derive their printed text from
 * PalletKind::LABELS, not the word "pallet".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pallets MODIFY COLUMN container_type ENUM('pallet', 'gaylord', 'box', 'bag') NOT NULL DEFAULT 'pallet'");
    }

    public function down(): void
    {
        DB::statement("UPDATE pallets SET container_type = 'pallet' WHERE container_type IN ('box', 'bag')");
        DB::statement("ALTER TABLE pallets MODIFY COLUMN container_type ENUM('pallet', 'gaylord') NOT NULL DEFAULT 'pallet'");
    }
};
