<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\SetupCode;
use App\Models\Control\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

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

    /** The session key that remembers a visitor typed this warehouse's code (WarehouseSetupController). */
    public const CLAIM = 'setup_claim';

    /** The code this session proved it holds, if it is still usable: revoking a code ends a claim at once. */
    public function claimed(Request $request, Warehouse $warehouse): ?SetupCode
    {
        $id = $request->session()->get(self::CLAIM);

        return $id ? SetupCode::usable()->where('warehouse_id', $warehouse->id)->whereKey($id)->first() : null;
    }

    /** Wrong tries (a wrong code, a wrong password at the setup screen) are counted for the whole warehouse, not per visitor. */
    private function attemptKey(Warehouse $warehouse): string
    {
        return "warehouse-setup:{$warehouse->id}";
    }

    public function locked(Warehouse $warehouse): bool
    {
        return RateLimiter::tooManyAttempts($this->attemptKey($warehouse), (int) config('warehouses.setup_attempts'));
    }

    /** @throws ValidationException when the warehouse has had too many wrong tries; the message goes on $field */
    public function guardAttempts(Warehouse $warehouse, string $field): void
    {
        if ($this->locked($warehouse)) {
            $minutes = (int) ceil(RateLimiter::availableIn($this->attemptKey($warehouse)) / 60);
            throw ValidationException::withMessages([$field => "Too many wrong tries. Wait {$minutes} minute(s) and try again."]);
        }
    }

    public function fail(Warehouse $warehouse): void
    {
        RateLimiter::hit($this->attemptKey($warehouse), (int) config('warehouses.setup_attempt_minutes') * 60);
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
