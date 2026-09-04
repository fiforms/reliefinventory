<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use App\Models\Concerns\HasLoginGate;
use App\Models\Concerns\HasPermissions;
use App\Models\Concerns\HasPinLogin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Person extends Model
{
    use HasFactory, HasLoginGate, HasPermissions, HasPinLogin;

    public const PARTNER_STATUS_PENDING = 'pending';

    public const PARTNER_STATUS_APPROVED = 'approved';

    public const PARTNER_STATUS_DENIED = 'denied';

    public const PARTNER_STATUS_BLOCKED = 'blocked';

    /**
     * Legal moves for partner_status — mirrors the pending/approved/denied/
     * blocked shape from the Facility approval design (Part 5, not yet
     * built), applied here to the party record that actually exists today.
     * `null` (never tracked) can move to any of the three entry points;
     * `denied` can be reconsidered back to pending or straight to approved;
     * `blocked` can only be lifted back to approved or converted to denied
     * — never silently back to "never tracked".
     */
    private const PARTNER_STATUS_TRANSITIONS = [
        '' => [self::PARTNER_STATUS_PENDING, self::PARTNER_STATUS_APPROVED, self::PARTNER_STATUS_DENIED],
        self::PARTNER_STATUS_PENDING => [self::PARTNER_STATUS_APPROVED, self::PARTNER_STATUS_DENIED],
        self::PARTNER_STATUS_APPROVED => [self::PARTNER_STATUS_BLOCKED, self::PARTNER_STATUS_DENIED],
        self::PARTNER_STATUS_DENIED => [self::PARTNER_STATUS_PENDING, self::PARTNER_STATUS_APPROVED],
        self::PARTNER_STATUS_BLOCKED => [self::PARTNER_STATUS_APPROVED, self::PARTNER_STATUS_DENIED],
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'people';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'organization',
        // Marks this Person as the org record itself (not inferred from
        // organization being set, since a contact row also wants to record
        // its own org affiliation) — see parent_person_id below.
        'is_organization',
        // Self-referential: links a contact Person to the org Person they
        // belong to. Null for standalone people and for org records
        // themselves.
        'parent_person_id',
        // Free-text relationship tag for a contact under a parent org
        // (Primary/Delivery/Billing/...) — deliberately not a governed
        // lookup table, since real Flowtrac contact-role data showed the
        // role flags going unused or non-exclusive.
        'contact_role',
        // Open-ended party-type tag (Donor/Supplier/Warehouse Contact/...),
        // see PersonCategory.
        'category_id',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'zip',
        'county_id',
        'comments',
        // Whether this person is a volunteer (unpaid) — a fact about the
        // person, independent of whatever permission role they hold (a
        // volunteer can be the office manager or an administrator). Feeds
        // future volunteer-hours/FEMA-reporting tracking; editable from
        // both PeopleController and UserAdminController.
        'is_volunteer',
        // Gates the volunteer kiosk's default tile grid — the single flag
        // both the grid query and admin UI check. Admins can toggle it
        // directly at any time; the window fields below drive it
        // automatically for a known-duration commitment without requiring
        // that manual follow-up. See volunteers:sync-active-windows.
        'volunteer_active',
        'volunteer_window_start',
        'volunteer_window_end',
        // Admin-assigned (the physical badge is issued by staff), unlike
        // pin_hash below which is deliberately NOT fillable — a PIN is
        // self-service and must only ever be written by PinController,
        // never by a raw mass-assignment payload through PeopleController.
        'badge_code',
        // Both set only by UserAdminController (User Administration page),
        // never accepted from client-submitted validation rules elsewhere
        // — see PeopleController/UserAdminController's VALIDATION_RULES.
        'email_verified_at',
        'disabled_at',
        'approved_at',
        // Explicit source-system/source-ref pair for import idempotency —
        // see the 2026_08_20 import-framework migration.
        'source_system',
        'source_ref',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'disabled_at' => 'datetime',
        'approved_at' => 'datetime',
        'address_verified_at' => 'datetime',
        'is_volunteer' => 'boolean',
        'volunteer_active' => 'boolean',
        'volunteer_window_start' => 'date',
        'volunteer_window_end' => 'date',
        'is_organization' => 'boolean',
    ];

    /**
     * Person had no $hidden at all until this was found (2026-08-16) while
     * checking whether the new pin_hash column would leak the same way —
     * it would have, and password already was: PeopleController::index()
     * serializes full Person models with no column restriction, so every
     * bcrypt password hash was reaching the browser for anyone holding
     * manage-people (the whole volunteer tier, by default). Matches what
     * User::$hidden already correctly did for the same table.
     */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    /**
     * Combined display name for search/combo controls — there is no single
     * name column, and controls like ComboBox can only display one field.
     */
    protected $appends = ['full_name', 'search_label'];

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        // Organization-only records (no personal name) still need a visible label
        return $name !== '' ? $name : ($this->organization ?? '');
    }

    /**
     * "Person - Organization" label for search/combo controls (SearchSelect) —
     * shows both where present, falls back to whichever one exists alone.
     * Reintroduced after being lost in a rolled-back update.
     */
    public function getSearchLabelAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        $org = trim($this->organization ?? '');

        if ($name === '') {
            return $org;
        }

        return $org === '' ? $name : "{$name} - {$org}";
    }

    /**
     * system_key marks a record as system-provided (e.g. the canonical
     * "Unknown Donor" placeholder) — deliberately not in $fillable, so it
     * can only ever be set directly by a migration, never through the
     * People form/API.
     */
    public function isSystem(): bool
    {
        return ! is_null($this->system_key);
    }

    /**
     * Define relationships to other models (if applicable).
     */

    // Every order/donation Transaction where this person is the donor/recipient.
    // Fixed 2026-08-23: previously referenced a nonexistent OrderDonation
    // class and was never actually usable — see DonationOfferController's
    // donor-history use for the first real caller.
    public function orderDonations()
    {
        return $this->hasMany(Transaction::class, 'person_id');
    }

    // Pre-arrival donation offers where this person is the donor — see
    // DonationOffer for the offered/accepted/pending/... lifecycle.
    public function donationOffers()
    {
        return $this->hasMany(DonationOffer::class, 'person_id');
    }

    public function county()
    {
        return $this->belongsTo(County::class, 'county_id');
    }

    public function volunteerSignIns()
    {
        return $this->hasMany(VolunteerSignIn::class)->orderByDesc('signed_in_at');
    }

    /**
     * The kiosk grid's per-tile state: an open (or pending_confirmation —
     * a forgotten sign-out still needing to be resolved) sign-in, if any
     * — but only if it's within the current building-empty window, i.e.
     * matches VolunteerSignIn::scopeOccupying(). This is what makes the
     * tile grid agree with the occupancy count it drives: after a
     * "Confirm Building Empty" closeout, a tile shouldn't still read
     * "signed in" for someone whose row predates it. See
     * forgottenSignIn() for that older-than-the-last-closeout case — the
     * kiosk offers a "you forgot to sign out" resolution for it instead
     * of just hiding it (which would otherwise dead-end: store() still
     * sees the row as open and refuses a fresh sign-in).
     */
    public function currentSignIn()
    {
        $lastCloseoutAt = BuildingCloseout::max('closed_at');

        return $this->hasOne(VolunteerSignIn::class)
            ->whereIn('status', [VolunteerSignIn::STATUS_OPEN, VolunteerSignIn::STATUS_PENDING_CONFIRMATION])
            ->when($lastCloseoutAt, fn ($q) => $q->where('signed_in_at', '>', $lastCloseoutAt))
            ->latestOfMany('signed_in_at');
    }

    /**
     * An open/pending_confirmation sign-in from before the last building
     * closeout — the building's been confirmed empty since, but this
     * person's row was never actually closed out. Surfaced separately
     * from currentSignIn() so the kiosk can offer a friendly "you forgot
     * to sign out — what time did you leave?" resolution instead of a
     * dead-end "already signed in" error on their next sign-in attempt.
     */
    public function forgottenSignIn()
    {
        $lastCloseoutAt = BuildingCloseout::max('closed_at') ?? '1970-01-01 00:00:00';

        return $this->hasOne(VolunteerSignIn::class)
            ->whereIn('status', [VolunteerSignIn::STATUS_OPEN, VolunteerSignIn::STATUS_PENDING_CONFIRMATION])
            ->where('signed_in_at', '<=', $lastCloseoutAt)
            ->latestOfMany('signed_in_at');
    }

    /**
     * Most recent closed sign-in — feeds the confirm screen's
     * agency/work-description suggestion (a prefill, not a stored fact:
     * agency can change visit to visit, see volunteer-hours-tracking-design
     * memory).
     */
    public function lastSignIn()
    {
        return $this->hasOne(VolunteerSignIn::class)
            ->where('status', VolunteerSignIn::STATUS_CLOSED)
            ->latestOfMany('signed_in_at');
    }

    /**
     * The org Person this contact belongs to (null for standalone people
     * and for org records themselves).
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_person_id');
    }

    /**
     * Contacts linked to this org Person via parent_person_id.
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_person_id');
    }

    public function category()
    {
        return $this->belongsTo(PersonCategory::class, 'category_id');
    }

    /**
     * Assign a role to a person.
     *
     * @param  mixed  $role
     */
    public function assignRole($role)
    {
        if (is_numeric($role)) {
            $this->roles()->attach($role);
        } elseif ($role instanceof Role) {
            $this->roles()->attach($role->id);
        } elseif (is_string($role)) {
            $role = Role::where('name', $role)->first();
            if ($role) {
                $this->roles()->attach($role->id);
            }
        }
    }

    /**
     * Remove a role from a person.
     *
     * @param  mixed  $role
     */
    public function removeRole($role)
    {
        if (is_numeric($role)) {
            $this->roles()->detach($role);
        } elseif ($role instanceof Role) {
            $this->roles()->detach($role->id);
        } elseif (is_string($role)) {
            $role = Role::where('name', $role)->first();
            if ($role) {
                $this->roles()->detach($role->id);
            }
        }
    }

    /**
     * Check if the person has a specific role.
     *
     * @param  string  $roleName
     * @return bool
     */
    public function hasRole($roleName)
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Sync roles (remove old and add new roles).
     */
    public function syncRoles(array $roles)
    {
        $roleIds = Role::whereIn('name', $roles)->pluck('id')->toArray();
        $this->roles()->sync($roleIds);
    }

    public function partnerStatusLogs()
    {
        return $this->hasMany(PersonPartnerStatusLog::class)->orderBy('created_at');
    }

    /**
     * Move partner_status to a new value, applying the legal-move check and
     * appending an audit-log row in one transaction — the only place this
     * column is ever written (never mass-assignable, same as disabled_at/
     * email_verified_at). Mirrors DonationOffer::transitionTo()/
     * FormSubmission::transitionTo(). $formSubmissionId is optional
     * provenance for the specific case of a form-approval action driving
     * this transition (see FormSubmissionController::resolvePartnerPerson).
     */
    public function transitionPartnerStatus(
        string $toStatus,
        ?int $changedByPersonId,
        ?string $notes = null,
        ?int $formSubmissionId = null
    ): void {
        $from = $this->partner_status ?? '';

        if (! in_array($toStatus, self::PARTNER_STATUS_TRANSITIONS[$from] ?? [], true)) {
            $fromLabel = $from === '' ? '(not tracked)' : $from;
            throw new InvalidArgumentException("Cannot move partner_status from \"{$fromLabel}\" to \"{$toStatus}\".");
        }

        DB::transaction(function () use ($from, $toStatus, $changedByPersonId, $notes, $formSubmissionId) {
            $this->partner_status = $toStatus;
            $this->save();

            $this->partnerStatusLogs()->create([
                'from_status' => $from === '' ? null : $from,
                'to_status' => $toStatus,
                'changed_by_person_id' => $changedByPersonId,
                'form_submission_id' => $formSubmissionId,
                'notes' => $notes,
            ]);
        });
    }
}
