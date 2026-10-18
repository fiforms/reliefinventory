<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintJobStatusLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'print_job_status_log';

    protected $fillable = [
        'print_job_id',
        'from_status',
        'to_status',
        'changed_by_person_id',
        'managed_device_id',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function printJob()
    {
        return $this->belongsTo(PrintJob::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(Person::class, 'changed_by_person_id');
    }
}
