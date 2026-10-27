<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;

/**
 * A warehouse's place on disk (MULTI_WAREHOUSE.md §2, §11): its key file and its files folder. Idempotent; run
 * by `instance:migrate` before each warehouse is migrated.
 *
 * The default warehouse is an existing instance moving to this layout (§15): its key is the APP_KEY everything
 * was encrypted with until now, and its uploads move from storage/app/private into its own folder (the paths
 * stored in the database are relative to the disk root, so they stay valid).
 */
class WarehouseLayout
{
    /** @return list<string> what was done, for the command's output */
    public function ensure(Warehouse $warehouse): array
    {
        $done = [];

        if (! $warehouse->hasKey()) {
            $warehouse->writeKey($warehouse->is_default
                ? (string) config('app.key')
                : 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))));
            $done[] = 'wrote its key file';
        }

        $files = $warehouse->storagePath();
        $legacy = storage_path('app/private');
        if ($warehouse->is_default && ! $this->isEmpty($legacy) && $this->isEmpty($files)) {
            File::ensureDirectoryExists($files);
            // Entry by entry, so the checkout's own storage/app/private/.gitignore stays where git expects it.
            foreach (array_diff(scandir($legacy), ['.', '..', '.gitignore']) as $entry) {
                if (! rename("{$legacy}/{$entry}", "{$files}/{$entry}")) {
                    throw new \RuntimeException("Could not move {$legacy}/{$entry} to {$files}.");
                }
            }
            $done[] = "moved its uploads from {$legacy}";
        }
        File::ensureDirectoryExists($files);

        return $done;
    }

    /** Nothing in it but (at most) a .gitignore. */
    private function isEmpty(string $dir): bool
    {
        return ! is_dir($dir) || array_diff(scandir($dir), ['.', '..', '.gitignore']) === [];
    }
}
