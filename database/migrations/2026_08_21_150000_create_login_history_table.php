<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A permanent record of successful logins, separate from the sessions
     * table (which only tracks current/recent presence and is overwritten
     * on every request). Covers both auth entry points — email/password
     * (AuthenticatedSessionController) and PIN unlock (UnlockController) —
     * with a `method` column distinguishing them.
     */
    public function up(): void
    {
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('method');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('logged_in_at');
            $table->timestamps();

            $table->index(['person_id', 'logged_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_history');
    }
};
