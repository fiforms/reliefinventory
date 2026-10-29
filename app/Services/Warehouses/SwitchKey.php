<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

/**
 * Puts the warehouse's data under its WAREHOUSE_KEY (MULTI_WAREHOUSE.md §5): `encrypted` model casts and signed
 * URLs. Everything else stays on the instance's APP_KEY (`app.key` and the container's encrypter, never switched):
 * cookies, the CSRF token, the queue's encrypted jobs, and account-level signed links (InstanceSignedUrl). So
 * app code must not encrypt warehouse data with `Crypt` directly — use Model::currentEncrypter(); a guard test
 * enforces it.
 */
class SwitchKey implements SwitchTask
{
    public function makeCurrent(Warehouse $warehouse): void
    {
        $key = $warehouse->key();

        Model::encryptUsing(new Encrypter(self::rawKey($key), config('app.cipher')));
        app('url')->setKeyResolver(fn () => [$key]);
    }

    public function forgetCurrent(): void
    {
        Model::encryptUsing(null);
        app('url')->setKeyResolver(fn () => [config('app.key'), ...config('app.previous_keys', [])]);
    }

    private static function rawKey(string $key): string
    {
        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
