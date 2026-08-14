<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields Receiving needs to capture at dock-side intake, per
 * receiving-sorting-workflow.md. All nullable — only meaningful for
 * type=donation rows; orders never populate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            // Not everything received is a donation — only "donation" rows
            // proceed into the sorting pipeline. equipment/supplies/other
            // downstream tracking is explicitly undesigned (see memory).
            $table->enum('category', ['donation', 'equipment', 'supplies', 'other'])
                ->nullable()
                ->after('type');

            // Rough count entered at receiving — advisory, never the
            // source of truth (that's sorting). See "intake precision" rule.
            $table->unsignedInteger('container_count')->nullable()->after('category');

            // Free-text paragraph description of contents, captured fast
            // at the dock.
            $table->text('manifest')->nullable()->after('container_count');

            // Shipment-level, advisory, never derived into a per-pallet or
            // per-item count.
            $table->decimal('manifest_weight_lbs', 10, 2)->nullable()->after('manifest');

            // Set whenever status_id changes (see Transaction::booted()) so
            // the daily close-out report is a plain indexed query rather
            // than a reconstruction from history.
            $table->timestamp('status_changed_at')->nullable()->after('status_id');
        });
    }

    public function down(): void
    {
        Schema::table('orderdonations', function (Blueprint $table) {
            $table->dropColumn(['category', 'container_count', 'manifest', 'manifest_weight_lbs', 'status_changed_at']);
        });
    }
};
