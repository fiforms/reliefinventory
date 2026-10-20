<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DeviceRelease;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads for people who can pair devices (manage-devices): Raspberry Pi SD card
 * images, virtual machine images and the RI Print Client. Uploaded and managed by
 * manage-firmware holders (DeviceReleaseController); never offered to devices.
 */
class DeviceDownloadController extends Controller
{
    public function index()
    {
        $records = DeviceRelease::orderByDesc('created_at')->get()
            ->filter(fn (DeviceRelease $r) => $r->isDownloadOnly())
            ->values();

        return response()->json([
            'records' => $records,
            'platforms' => DeviceRelease::PRINT_CLIENT_PLATFORMS,
        ]);
    }

    public function download(DeviceRelease $deviceRelease, string $filename)
    {
        abort_unless($deviceRelease->isDownloadOnly(), 404);
        abort_unless(Storage::disk('local')->exists($deviceRelease->storage_path), 404);

        return response()->download(Storage::disk('local')->path($deviceRelease->storage_path), $deviceRelease->filename);
    }
}
