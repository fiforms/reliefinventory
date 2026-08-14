<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the old created/wrapped/moved/unwrapped/archived pallet model
 * with the five-kind design (pallet-container-model): Receiving, Warehouse,
 * Shipping, Hold, Quarantine — each its own lifecycle, fixed at labeling.
 * `status` is a plain string (not a DB enum) because each kind has its own
 * vocabulary; validity is enforced in app code (see App\Support\PalletKind)
 * so adding a status later doesn't require a migration.
 *
 * No production data exists anywhere for this system, so this is a clean
 * replacement, not a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropColumn('last_status');
            $table->renameColumn('last_location_id', 'location_id');
        });

        Schema::table('pallets', function (Blueprint $table) {
            // R/W/S/H/Q — fixed forever once a pallet is labeled. Reusing
            // physical wood for a new load means a new label and a new
            // record (LPN model), never changing an existing one's kind.
            $table->enum('kind', ['R', 'W', 'S', 'H', 'Q'])->after('id');

            // Current lifecycle status for this kind, or "missing" (the
            // universal exception, restorable to the prior status on
            // re-scan — see status_before_missing below).
            $table->string('status')->after('kind');
            $table->string('status_before_missing')->nullable()->after('status');

            // A gaylord is "just a big box" functionally but needs the same
            // handling equipment as a pallet, so it shares this table/model
            // rather than living in the lighter generic Container model.
            $table->enum('container_type', ['pallet', 'gaylord'])->default('pallet')->after('status_before_missing');

            $table->foreignId('donor_person_id')->nullable()->after('container_type')->constrained('people')->nullOnDelete();
            $table->foreignId('destination_person_id')->nullable()->after('donor_person_id')->constrained('people')->nullOnDelete();
            $table->foreignId('truck_id')->nullable()->after('destination_person_id')->constrained('trucks')->nullOnDelete();

            // FEFO on W pallets; blank = no expiry pressure.
            $table->date('earliest_expiry')->nullable()->after('truck_id');

            // Empty-pallet QC: pending -> good | condemned -> recycled.
            // Sorters get one optional tap; a supervisor QC page has final say.
            $table->enum('condition', ['pending', 'good', 'condemned'])->nullable()->after('earliest_expiry');
        });

        Schema::table('palletstatus', function (Blueprint $table) {
            // Was an ENUM tied to the old model; each kind now has its own
            // status vocabulary, so this has to be a free string too.
            $table->string('status')->change();
            $table->text('notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('palletstatus', function (Blueprint $table) {
            $table->dropColumn('notes');
            DB::statement("ALTER TABLE palletstatus MODIFY COLUMN status ENUM('created', 'wrapped', 'moved', 'unwrapped', 'archived') NOT NULL");
        });

        Schema::table('pallets', function (Blueprint $table) {
            $table->dropForeign(['donor_person_id']);
            $table->dropForeign(['destination_person_id']);
            $table->dropForeign(['truck_id']);
            $table->dropColumn([
                'kind', 'status', 'status_before_missing', 'container_type',
                'donor_person_id', 'destination_person_id', 'truck_id',
                'earliest_expiry', 'condition',
            ]);
        });

        Schema::table('pallets', function (Blueprint $table) {
            $table->renameColumn('location_id', 'last_location_id');
            $table->enum('last_status', ['created', 'wrapped', 'moved', 'unwrapped', 'archived'])->nullable();
        });
    }
};
