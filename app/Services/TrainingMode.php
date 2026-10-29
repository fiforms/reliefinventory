<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\TrustedDevice;
use App\Services\Warehouses\WarehouseContext;

/**
 * Training in a warehouse of its own (MULTI_WAREHOUSE.md §12). A training warehouse is an ordinary warehouse marked
 * `is_training`: it is the only place the class features exist (trainee tap-login, /join, report cards, certificates, training
 * products, Prepare Training, the activity log). Nothing here changes how a real warehouse behaves, and there is no mode flag:
 * the warehouse being reachable is the "on" switch, and an instance admin turns it off by suspending it
 * (`warehouse:training disable`). Tap-login stays on an approved device (TrustedDevice, the one PIN unlock uses) and gives a
 * trainee only what the Trainee role grants (TRAINING_KEYS).
 */
class TrainingMode
{
    public const ROLE = 'Trainee';

    /**
     * What the Trainee role grants: the six stations' work, plus reports and printing. Nothing in Setup, except
     * the catalog keys Sorting's own lookups need (/json/items, itemtypes, units, categories, and adding a new item).
     */
    public const TRAINING_KEYS = [
        'general-access', 'manage-receiving', 'manage-donation-offers', 'manage-sorting', 'manage-pallets',
        'manage-people', 'manage-orders', 'view-reports', 'print-documents',
        'manage-items', 'manage-itemtypes', 'manage-units', 'manage-categories',
    ];

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

    /** Whether the warehouse this request, job or command works in is a training warehouse. */
    public static function active(): bool
    {
        $context = app(WarehouseContext::class);

        return $context->has() && (bool) $context->current()->is_training;
    }

    /** Training login is offered on this device: this is a training warehouse and the device is approved. */
    public static function availableOn(?TrustedDevice $device): bool
    {
        return (bool) $device?->isApproved() && self::active();
    }
}
