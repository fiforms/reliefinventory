<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Supports centralized feedback aggregation (see
 * docs/centralized-feedback-design-brief.md): a "remote" instance pushes its
 * own reports to a "central" one, which stores them in this same table so
 * /setup/feedback's triage list stays a single unified view rather than a
 * separate merge step.
 *
 * - `uuid`: origin-stamped, globally-unique per report — generated at
 *   creation (FeedbackReport::booted()) on every instance, and part of the
 *   idempotency key central upserts on, so a retried push can't double-insert.
 * - `instance_identifier`: null for a report created locally on *this*
 *   instance (the normal case everywhere); non-null only on central, tagging
 *   which remote instance an ingested report came from.
 * - `(instance_identifier, uuid)` unique: the real DB-level idempotency
 *   guard central's ingest upsert relies on — see FeedbackIngestController.
 * - `reporter_name` / `reporter_email`: denormalized identity for an
 *   ingested report, since central has no access to a remote's `people`
 *   table and this can't be a foreign key. Unused (null) for locally-created
 *   reports, which already have a real `person_id`.
 * - `submitted_at`: remote-instance-only concept — null until central
 *   acknowledges receiving this report's uuid (see FeedbackPushService).
 *   Meaningless (left null) for reports that were always local to the
 *   instance showing them, since those never need to go anywhere.
 * - `person_id` becomes nullable: an ingested report has no local Person to
 *   point to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->change();
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('instance_identifier')->nullable()->after('uuid');
            $table->string('reporter_name')->nullable()->after('person_id');
            $table->string('reporter_email')->nullable()->after('reporter_name');
            $table->timestamp('submitted_at')->nullable()->after('status_changed_at');
        });

        // Backfill existing rows (all locally-created, so instance_identifier
        // stays null) before the unique index below is added.
        DB::table('feedback_reports')->whereNull('uuid')->orderBy('id')->chunkById(100, function ($reports) {
            foreach ($reports as $report) {
                DB::table('feedback_reports')->where('id', $report->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });

        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->unique(['instance_identifier', 'uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropUnique(['instance_identifier', 'uuid']);
            $table->dropColumn(['uuid', 'instance_identifier', 'reporter_name', 'reporter_email', 'submitted_at']);
        });

        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable(false)->change();
        });
    }
};
