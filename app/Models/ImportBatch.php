<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per upload. Preview never writes app data — only Commit does,
 * and Commit is safe to re-run (idempotent, matched via source_system/
 * source_ref) since Washington runs Flowtrac and reliefinventory in
 * parallel for a while, not a one-time cutover.
 */
class ImportBatch extends Model
{
    protected $table = 'import_batches';

    protected $fillable = [
        'source',
        'file_type',
        'original_filename',
        'stored_path',
        'status',
        'summary',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(ImportBatchRow::class, 'import_batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(Person::class, 'created_by');
    }
}
