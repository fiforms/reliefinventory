<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\TrainingSetting;
use App\Models\TrustedDevice;
use Illuminate\Support\Facades\Auth;

/**
 * Training Mode: one switch (training_settings.enabled, `admin-system`) that changes only how people log in.
 * While it's on, an approved device (TrustedDevice, the same approval PIN login uses) shows a tap-your-name screen
 * (TrainingLoginController) for people flagged as trainees, with no password. That login is capped to
 * TRAINING_KEYS — HasPermissions::effectivePermissionKeys() intersects with it — so the menu, `permission:`
 * middleware and every hasPermission() check keep working unchanged, and anything outside it needs a full login
 * (CheckPermission shows the "needs a full login" page). Everything else in the system behaves exactly as normal.
 */
class TrainingMode
{
    public const ROLE = 'Trainee';

    /** What a tap-your-name login can do: the six stations' work, plus reports and printing. Nothing in Setup. */
    public const TRAINING_KEYS = [
        'general-access', 'manage-receiving', 'manage-donation-offers', 'manage-sorting', 'manage-pallets',
        'manage-people', 'manage-orders', 'view-reports', 'print-documents',
    ];

    /** Session key holding the id of the person a training (tap-your-name) login was made for. */
    public const SESSION_KEY = 'training_login_person_id';

    public const TEAMS = [1, 2, 3, 4, 5];

    /** Station number => name. The class rotates station k -> k+1, and 6 -> 1. */
    public const STATIONS = [
        1 => 'Receiving',
        2 => 'Sorting',
        3 => 'Inventory',
        4 => 'Picking',
        5 => 'Shipping',
        6 => 'Delivery / Ordering',
    ];

    public static function enabled(): bool
    {
        return (bool) TrainingSetting::current()->enabled;
    }

    /** True when this request is a training login for that person — the permission cap applies. */
    public static function capApplies(int $personId): bool
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return false;
        }

        return Auth::id() === $personId && (int) request()->session()->get(self::SESSION_KEY) === $personId;
    }

    public static function isTrainingSession(): bool
    {
        return Auth::check() && self::capApplies((int) Auth::id());
    }

    /** Training login is offered on this device: training mode is on and the device is approved. */
    public static function availableOn(TrustedDevice $device): bool
    {
        return $device->isApproved() && self::enabled();
    }
}
