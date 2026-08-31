<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormAnswer extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'form_submission_id',
        'form_question_id',
        'question_label_snapshot',
        'question_type_snapshot',
        'value_text',
        'value_json',
    ];

    protected $casts = [
        'value_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function question()
    {
        return $this->belongsTo(FormQuestion::class, 'form_question_id');
    }

    /**
     * Plain-text display value regardless of whether it's a single
     * value_text answer or a multiple_choice value_json array.
     */
    public function displayValue(): string
    {
        if ($this->value_json !== null) {
            return implode(', ', $this->value_json);
        }

        return (string) $this->value_text;
    }
}
