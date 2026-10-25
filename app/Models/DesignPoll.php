<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Whether a Design Votes poll (App\Support\DesignPolls) is open. The poll's text and options are in code. */
class DesignPoll extends Model
{
    protected $fillable = ['slug', 'is_open', 'opened_at', 'closed_at'];

    protected $casts = [
        'is_open' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** The state row for a poll in the registry, made (closed) if a migration hasn't added it. */
    public static function forSlug(string $slug): self
    {
        return self::firstOrCreate(['slug' => $slug], ['is_open' => false]);
    }
}
