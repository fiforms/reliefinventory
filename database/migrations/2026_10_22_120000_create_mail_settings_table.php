<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton settings row (always id=1), same pattern as
     * FeedbackAggregationSetting — outgoing mail (mailer, From address/name,
     * Resend API key, SMTP server) plus the feedback-report recipient list,
     * edited on /setup/email instead of MAIL_* / RESEND_KEY /
     * FEEDBACK_NOTIFY_EMAIL in .env. A null column means "use the .env
     * value" (see App\Services\MailSettings). Secrets use the `encrypted` cast.
     */
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mailer')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->text('resend_key')->nullable();
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->text('feedback_notify_emails')->nullable();
            $table->timestamps();
        });

        // Starts empty: every null column falls back to .env, so an instance already sending mail is unchanged.
        DB::table('mail_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
