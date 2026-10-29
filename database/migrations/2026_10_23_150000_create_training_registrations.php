<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trainee self sign-up (/join, TraineeSignupController): each sign-up makes (or reuses) a trainee Person and keeps
 * the follow-up answers here — the contact email (kept off people.email, which holds a trainee's placeholder login
 * address), whether they want project updates, how they'd like to help, and anyone they'd recommend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('wants_updates')->default(false);
            $table->boolean('help_volunteer')->default(false);
            $table->boolean('help_software')->default(false);
            $table->boolean('help_organization')->default(false);
            $table->boolean('help_other')->default(false);
            $table->text('recommend')->nullable(); // someone they'd recommend to help: a name, maybe contact details
            $table->text('comments')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_registrations');
    }
};
