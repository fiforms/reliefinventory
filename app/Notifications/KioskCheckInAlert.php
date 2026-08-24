<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Notifications;

use App\Models\VolunteerSignIn;
use Illuminate\Notifications\Notification;

/**
 * Kiosk check-in alert — Guest and first-time-volunteer sign-ins only, per
 * the volunteer-kiosk design (see VolunteerSignInController::
 * maybeNotifyCheckIn()). Deliberately never fires for a routine returning
 * volunteer, so alerts stay meaningful.
 *
 * Database-only for now (in-app bell in AuthenticatedLayout.vue). Built so
 * SMS/push are additive later, not a rework: toDatabase() already carries
 * everything a toVonage()/toWebPush() method would need (name, why they're
 * here, when), so adding a channel is just appending to via() plus writing
 * the matching to*() method — nothing about when/why this notification
 * fires needs to change.
 */
class KioskCheckInAlert extends Notification
{
    public function __construct(private readonly VolunteerSignIn $signIn) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        $person = $this->signIn->person;
        $isVolunteer = $this->signIn->category === VolunteerSignIn::CATEGORY_VOLUNTEER;

        return [
            'kind' => $isVolunteer ? 'new_volunteer' : 'guest',
            'volunteer_sign_in_id' => $this->signIn->id,
            'person_id' => $person?->id,
            'name' => $person?->full_name,
            'category_label' => $isVolunteer
                ? 'New Volunteer'
                : ($this->signIn->otherCategory?->name ?? $this->signIn->other_category_text ?? 'Other'),
            'signed_in_at' => $this->signIn->signed_in_at?->toIso8601String(),
        ];
    }
}
