<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Services\BackupService;
use Illuminate\Database\Eloquent\Model;

/** One on-demand backup zip. The file lives in this warehouse's own folder (BackupService::dir()); this row is its record. */
class Backup extends Model
{
    public const ACTIVE = ['queued', 'running'];

    // Written only by BackupService / BackupController, never from request input.
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function path(): string
    {
        return app(BackupService::class)->dir().'/'.$this->filename;
    }
}
