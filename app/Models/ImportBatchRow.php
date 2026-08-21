<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatchRow extends Model
{
    protected $table = 'import_batch_rows';

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'source_key',
        'outcome',
        'error_message',
        'raw_row',
        'mapped_entity_type',
        'mapped_entity_id',
    ];

    protected $casts = [
        'raw_row' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
