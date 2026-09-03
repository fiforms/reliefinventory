<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inserts 'review' between 'in_development' and 'resolved': a fix that's been implemented
 * and deployed lands here first, not straight at Resolved — only a human, through the
 * /setup/feedback UI, can advance a report out of Review, so a deployed fix always gets a
 * "does this actually work" check before the reporter is told it's done. Nothing currently
 * writes 'review' automatically (no code in this repo implements or deploys a fix on a
 * report's behalf yet); the status exists now so that future capability has somewhere to
 * land other than straight to Resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE feedback_reports MODIFY status ENUM('new', 'seen', 'in_development', 'review', 'resolved') DEFAULT 'new'");
        DB::statement("ALTER TABLE feedback_report_status_logs MODIFY status ENUM('new', 'seen', 'in_development', 'review', 'resolved')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE feedback_report_status_logs MODIFY status ENUM('new', 'seen', 'in_development', 'resolved')");
        DB::statement("ALTER TABLE feedback_reports MODIFY status ENUM('new', 'seen', 'in_development', 'resolved') DEFAULT 'new'");
    }
};
