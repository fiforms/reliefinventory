<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormQuestion extends Model
{
    public const TYPES = [
        'short_text', 'long_text', 'number', 'date',
        'yes_no', 'single_choice', 'multiple_choice', 'section_header',
    ];

    public const CHOICE_TYPES = ['single_choice', 'multiple_choice'];

    protected $fillable = [
        'form_id',
        'order',
        'label',
        'help_text',
        'type',
        'options',
        'required',
        'preset_key',
        'target_field',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function isAnswerable(): bool
    {
        return $this->type !== 'section_header';
    }
}
