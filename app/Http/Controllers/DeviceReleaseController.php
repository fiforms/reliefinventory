<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DeviceRelease;
use App\Services\DeviceReleaseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Release catalogue admin (manage-devices): upload an app archive or OS
 * bundle, tag/untag it on update channels. Artifacts live on the local
 * (private) disk under a random directory and are served by
 * DeviceArtifactController.
 */
class DeviceReleaseController extends Controller
{
    public function index()
    {
        return response()->json(['records' => DeviceRelease::orderByDesc('created_at')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file',
            'kind' => ['required', Rule::in(['app', 'os'])],
            'version' => 'required|string|max:255',
            'architecture' => 'required|string|max:255',
            'release_type' => ['required', Rule::in(['full', 'hotfix', 'disk_image'])],
            'required_base_version' => 'required_if:release_type,hotfix|nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
        if ($data['kind'] === 'app' && $data['release_type'] !== 'full') {
            return response()->json(['message' => 'App releases are always full.', 'errors' => ['release_type' => ['App releases are always full.']]], 422);
        }

        $file = $request->file('file');
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file->getClientOriginalName()));
        $token = Str::random(40);
        $path = $file->storeAs("device-releases/{$token}", $filename, 'local');

        $release = DeviceRelease::create([
            'kind' => $data['kind'],
            'version' => $data['version'],
            'architecture' => $data['architecture'],
            'release_type' => $data['release_type'],
            'required_base_version' => $data['release_type'] === 'hotfix' ? $data['required_base_version'] : null,
            'notes' => $data['notes'] ?? null,
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'storage_path' => $path,
            'filename' => $filename,
            'size_bytes' => $file->getSize(),
            'download_token' => $token,
            'channels' => [],
            'uploaded_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Release uploaded.', 'record' => $release], 201);
    }

    public function tag(Request $request, DeviceRelease $deviceRelease, DeviceReleaseResolver $resolver)
    {
        $data = $request->validate(['channel' => ['required', Rule::in(config('devices.channels'))]]);
        $resolver->tag($deviceRelease, $data['channel']);

        return response()->json(['record' => $deviceRelease->fresh()]);
    }

    public function untag(Request $request, DeviceRelease $deviceRelease, DeviceReleaseResolver $resolver)
    {
        $data = $request->validate(['channel' => ['required', Rule::in(config('devices.channels'))]]);
        $resolver->untag($deviceRelease, $data['channel']);

        return response()->json(['record' => $deviceRelease->fresh()]);
    }

    public function destroy(DeviceRelease $deviceRelease)
    {
        if (! empty($deviceRelease->channels)) {
            return response()->json(['message' => 'Untag this release from all channels before deleting it.'], 422);
        }
        Storage::disk('local')->deleteDirectory(dirname($deviceRelease->storage_path));
        $deviceRelease->delete();

        return response()->json(['message' => 'Release deleted.']);
    }
}
