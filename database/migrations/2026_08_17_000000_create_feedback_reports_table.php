<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app bug/feature reports, captured with automatic page context
     * (URL, title, browser) plus an optional screenshot so a reporter
     * doesn't have to explain where they were or what they saw.
     */
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people');
            $table->enum('type', ['bug', 'feature']);
            $table->enum('status', ['new', 'seen', 'in_development', 'resolved'])->default('new');
            $table->timestamp('status_changed_at')->nullable();
            $table->text('message');
            $table->string('page_url');
            $table->string('page_title')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_report_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_report_id')->constrained('feedback_reports')->cascadeOnDelete();
            $table->enum('status', ['new', 'seen', 'in_development', 'resolved']);
            $table->text('comment')->nullable();
            $table->foreignId('person_id')->constrained('people');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_report_status_logs');
        Schema::dropIfExists('feedback_reports');
    }
};
