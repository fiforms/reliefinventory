<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;

/**
 * Issuing and checking the setup code of a new warehouse (MULTI_WAREHOUSE.md §7). A warehouse has at most one usable
 * code: issuing another revokes the old one. Using a code up is WarehouseSetup's job, in the same transaction as the
 * setup it unlocks.
 */
class SetupCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @return array{0: string, 1: SetupCode} the code as shown (`ABCDE-FGHJK`, never stored) and its row */
    public function issue(Warehouse $warehouse, ?string $createdBy = null): array
    {
        $plain = '';
        for ($i = 0; $i < 10; $i++) {
            $plain .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $row = (new SetupCode)->getConnection()->transaction(function () use ($warehouse, $plain, $createdBy) {
            SetupCode::where('warehouse_id', $warehouse->id)->usable()->update(['revoked_at' => now()]);

            return SetupCode::create([
                'warehouse_id' => $warehouse->id,
                'code_hash' => SetupCode::hash($plain),
                'expires_at' => now()->addDays((int) config('warehouses.setup_code_days')),
                'created_by' => $createdBy,
            ]);
        });

        return [substr($plain, 0, 5).'-'.substr($plain, 5), $row];
    }

    /** The usable code row for what was typed, or null (wrong, used, revoked, expired, or another warehouse's: alike). */
    public function find(Warehouse $warehouse, string $typed): ?SetupCode
    {
        return SetupCode::usable()->where('warehouse_id', $warehouse->id)->where('code_hash', SetupCode::hash($typed))->first();
    }

    public function revoke(Warehouse $warehouse): int
    {
        return SetupCode::where('warehouse_id', $warehouse->id)->usable()->update(['revoked_at' => now()]);
    }

    /** The code a warehouse still waits on, for the instance admin's list. */
    public function pending(Warehouse $warehouse): ?SetupCode
    {
        return SetupCode::usable()->where('warehouse_id', $warehouse->id)->latest('id')->first();
    }
}
