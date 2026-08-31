<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Seeded catalog backing the admin builder's checkbox quick-add picker.
 * Checking a box copies an editable FormQuestion onto the form — this row
 * is never referenced live afterward except for preset_key provenance.
 */
class FormQuestionPreset extends Model
{
    protected $fillable = [
        'key', 'label', 'category', 'type', 'options', 'help_text', 'target_field', 'order',
    ];

    protected $casts = [
        'options' => 'array',
    ];
}
