<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itemtypes', function (Blueprint $table) {
            // No production data exists anywhere for this system yet, so
            // there's nothing to preserve — the flat 5-digit `number` is
            // replaced outright by family/variant, not deprecated alongside
            // it. See HANDOFF-item-numbering.md.
            $table->dropColumn('number');

            // family: always exactly 4 digits, zero-padded ("0042", "1100",
            // "7777") — uniform width across every block, not just the
            // large-item ones. That fixed width is what makes the family/
            // variant boundary computable from position alone, with no dash
            // required for canonical/scanned input. Nullable to support a
            // sort-hold item type quick-added on the sorting floor with no
            // number assigned yet, pending supervisor review.
            $table->string('family', 4)->nullable();

            // variant: "00".."99", zero-padded 2 chars. "00" is the standard/
            // default item of a family where one exists (318-00 = household
            // paper towels); families with no "always sized" default (404
            // diapers, 950 tarps...) simply have no "00" row at all — that
            // absence, not a separate flag, is what marks "no default item."
            $table->string('variant', 2)->nullable()->after('family');

            // Orderable = normal catalog item. Sort-hold = valid at sorting
            // intake (incl. quick-added item types with no number yet) but
            // excluded from order forms until reviewed. Retired = frozen,
            // never reused.
            $table->enum('status', ['orderable', 'sort_hold', 'retired'])
                ->default('orderable')
                ->after('variant');

            // 1 = heavy/rigid (pallet bottom) .. 9 = light/crushable. Nullable
            // until a family's pick sequence is actually classified.
            $table->unsignedTinyInteger('pick_sequence')->nullable()->after('status');

            // F food, L liquid/leak risk, K chemical, A absorbent, N neutral.
            $table->enum('storage_class', ['F', 'L', 'K', 'A', 'N'])->nullable()->after('pick_sequence');

            $table->unique(['family', 'variant']);
            $table->index('status');
        });

        Schema::table('categories', function (Blueprint $table) {
            // The numeric block a category covers, e.g. 0-99 (animal
            // products), 1000-1399 (large appliances). Nullable — only
            // meaningful for the block-style categories the numbering
            // scheme introduces; validation/grouping aid, not a hard FK.
            $table->unsignedInteger('block_start')->nullable();
            $table->unsignedInteger('block_end')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['block_start', 'block_end']);
        });

        Schema::table('itemtypes', function (Blueprint $table) {
            $table->dropUnique(['family', 'variant']);
            $table->dropIndex(['status']);
            $table->dropColumn(['family', 'variant', 'status', 'pick_sequence', 'storage_class']);
            $table->string('number')->unique();
        });
    }
};
