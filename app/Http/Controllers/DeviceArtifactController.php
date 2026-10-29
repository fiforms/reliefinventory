<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DeviceRelease;
use Illuminate\Support\Facades\Storage;

/**
 * Serves release artifacts to devices with plain unauthenticated GETs (per
 * the contract); the unguessable per-release token in the URL is the access
 * control. BinaryFileResponse handles HTTP Range requests, which RAUC needs
 * to stream .raucb bundles. Fine for now; very large fleets should move
 * artifacts to a CDN/object store and just change download_url.
 */
class DeviceArtifactController extends Controller
{
    public function download(string $token, string $filename)
    {
        $release = DeviceRelease::where('download_token', $token)->firstOrFail();
        abort_if($release->isDownloadOnly(), 404); // people-only files: see DeviceDownloadController
        abort_unless(Storage::disk('local')->exists($release->storage_path), 404);

        return response()->file(Storage::disk('local')->path($release->storage_path), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($release->filename).'"',
        ]);
    }
}
