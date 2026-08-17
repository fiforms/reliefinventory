<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\BannerDismissal;
use App\Models\BannerSetting;

/**
 * Computes the banner Inertia prop shared on every request — active
 * type/message plus whether the current person has already dismissed the
 * banner at its current version. Kept out of HandleInertiaRequests itself
 * to mirror PinLoginService's role as the shared-prop computation point.
 */
class BannerService
{
    public function propsFor(?int $personId): array
    {
        $settings = BannerSetting::current();

        if (! $settings->type) {
            return ['active' => false];
        }

        $dismissed = $personId && BannerDismissal::where('person_id', $personId)
            ->where('version', $settings->version)
            ->exists();

        return [
            'active' => ! $dismissed,
            'type' => $settings->type,
            'message' => $settings->message,
            'version' => $settings->version,
        ];
    }
}
