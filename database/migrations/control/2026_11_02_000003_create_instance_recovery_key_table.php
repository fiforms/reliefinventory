<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The instance's recovery PUBLIC key: whole-instance backups seal every warehouse's key to it. One row. The private half is never stored
// anywhere on the server; it is shown once to the instance administrator. See App\Support\RecoveryKey, App\Models\Control\InstanceRecoveryKey.
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('instance_recovery_key', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();   // always 1
            $table->string('public_key', 43);               // base64url of the 32-byte X25519 public key
            $table->string('fingerprint', 19);              // AB12-CD34-EF56-7890: what a zip's manifest names
            $table->string('source', 20);                   // generated | adopted | environment
            $table->string('created_by')->nullable();       // the instance administrator's email, or "command line"
            // When the administrator said they had stored the private key. A key that was shown and never confirmed is flagged: if the page was
            // closed first, the private half is gone and a new pair has to be made.
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('instance_recovery_key');
    }
};
