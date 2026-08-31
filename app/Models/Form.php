<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable, admin-built survey/questionnaire definition. See the
 * migration doc comment for the field-by-field design; Partner Agency
 * Intake (seeded) is the first real form built on this, not a special case.
 */
class Form extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const ACCESS_PUBLIC = 'public';

    public const ACCESS_STAFF_ONLY = 'staff_only';

    public const ACCESS_BOTH = 'both';

    public const APPROVAL_NONE = 'none';

    public const APPROVAL_CREATE_OR_LINK_PARTNER = 'create_or_link_partner';

    protected $fillable = [
        'name',
        'slug',
        'intro_text',
        'status',
        'access_mode',
        'requires_approval',
        'on_approval_action',
        'notify_person_ids',
        'notify_emails',
        'created_by_person_id',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'notify_person_ids' => 'array',
    ];

    public function questions()
    {
        return $this->hasMany(FormQuestion::class)->orderBy('order');
    }

    public function submissions()
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(Person::class, 'created_by_person_id');
    }

    /**
     * Whether an unauthenticated visitor may load/submit this form.
     */
    public function allowsPublicAccess(): bool
    {
        return in_array($this->access_mode, [self::ACCESS_PUBLIC, self::ACCESS_BOTH], true);
    }

    /**
     * Whether a logged-in staff member may load/submit this form.
     */
    public function allowsStaffAccess(): bool
    {
        return in_array($this->access_mode, [self::ACCESS_STAFF_ONLY, self::ACCESS_BOTH], true);
    }
}
