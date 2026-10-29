<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Warehouses;

use App\Models\Control\Warehouse;
use Illuminate\Support\Facades\Storage;

/**
 * Points the `local` disk — every upload the app stores — at the warehouse's own files folder
 * (MULTI_WAREHOUSE.md §11). Stored paths are relative to the disk root, so they stay valid unchanged. The
 * filesystem manager caches disk instances, so the disk is forgotten on every switch.
 */
class SwitchStorage implements SwitchTask
{
    private string $base;

    public function __construct()
    {
        $this->base = config('filesystems.disks.local.root');
    }

    public function makeCurrent(Warehouse $warehouse): void
    {
        $this->use($warehouse->storagePath());
    }

    public function forgetCurrent(): void
    {
        $this->use($this->base);
    }

    private function use(string $root): void
    {
        if (config('filesystems.disks.local.root') === $root) {
            return;
        }

        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
    }
}
