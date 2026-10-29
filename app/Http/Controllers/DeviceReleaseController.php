<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DeviceRelease;
use App\Models\FirmwareSetting;
use App\Services\ChunkedUpload;
use App\Services\DeviceReleaseResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Release catalogue admin (manage-firmware): upload an app archive or OS
 * bundle, tag/untag it on update channels. Artifacts live on the local
 * (private) disk under a random directory and are served by
 * DeviceArtifactController.
 */
class DeviceReleaseController extends Controller
{
    public function index()
    {
        return response()->json([
            'records' => DeviceRelease::orderByDesc('created_at')->get(),
            'platforms' => DeviceRelease::PRINT_CLIENT_PLATFORMS,
        ]);
    }

    /** Where this instance's R Stations look for firmware: null = its own releases (below). */
    public function source()
    {
        return response()->json(['source_url' => FirmwareSetting::sourceUrl()]);
    }

    public function updateSource(Request $request)
    {
        $data = $request->validate(['source_url' => 'nullable|url:http,https|max:2048']);
        $url = isset($data['source_url']) ? rtrim(trim($data['source_url']), '/') : null;
        if ($url && rtrim(url('/'), '/') === $url) {
            return response()->json(['message' => 'That is this server. Leave the source empty to serve this server\'s own releases.', 'errors' => ['source_url' => ['That is this server.']]], 422);
        }
        FirmwareSetting::current()->update(['source_url' => $url ?: null]);

        return response()->json(['source_url' => $url ?: null]);
    }

    /** Shared by the one-shot and chunked upload paths. */
    private function metadataRules(): array
    {
        return [
            'kind' => ['required', Rule::in(['app', 'os', 'print_client'])],
            'version' => 'required|string|max:255',
            'architecture' => 'required|string|max:255',
            'release_type' => ['required', Rule::in(['full', 'hotfix', 'disk_image', 'vm_image'])],
            'required_base_version' => 'required_if:release_type,hotfix|nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }

    private function appReleasesMustBeFull(array $data): ?JsonResponse
    {
        if (in_array($data['kind'], ['app', 'print_client'], true) && $data['release_type'] !== 'full') {
            $message = $data['kind'] === 'app' ? 'App releases are always full.' : 'Print client releases are always full.';

            return response()->json(['message' => $message, 'errors' => ['release_type' => [$message]]], 422);
        }
        if ($data['kind'] === 'print_client' && ! isset(DeviceRelease::PRINT_CLIENT_PLATFORMS[$data['architecture']])) {
            $message = 'Pick one of: '.implode(', ', array_keys(DeviceRelease::PRINT_CLIENT_PLATFORMS)).'.';

            return response()->json(['message' => $message, 'errors' => ['architecture' => [$message]]], 422);
        }
        if (in_array($data['release_type'], DeviceRelease::IMAGE_TYPES, true) && $data['kind'] !== 'os') {
            $message = 'Disk and virtual machine images belong under the OS kind.';

            return response()->json(['message' => $message, 'errors' => ['kind' => [$message]]], 422);
        }

        return null;
    }

    private function safeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name)) ?: 'release.bin';
    }

    /** Records a release whose bytes are already on the local disk at $path. */
    private function createRelease(array $data, string $path, string $token, string $filename, int $size): DeviceRelease
    {
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
            'size_bytes' => $size,
            'download_token' => $token,
            'channels' => [],
            'uploaded_by' => Auth::id(),
        ]);

        $this->expireSupersededDownloads($release);

        return $release;
    }

    /**
     * Download-only files keep just the latest upload per platform: a new print client replaces the
     * older one for the same platform, a new SD card / VM image the older one of the same type and
     * architecture. The superseded rows and their files are deleted (there is no channel to protect).
     */
    private function expireSupersededDownloads(DeviceRelease $release): void
    {
        if (! $release->isDownloadOnly()) {
            return;
        }

        DeviceRelease::where('kind', $release->kind)
            ->where('release_type', $release->release_type)
            ->where('architecture', $release->architecture)
            ->where('id', '!=', $release->id)
            ->get()
            ->each(function (DeviceRelease $old) {
                Storage::disk('local')->deleteDirectory(dirname($old->storage_path));
                $old->delete();
            });
    }

    /** One-shot upload — fine for small files; large firmware goes through the chunked endpoints below. */
    public function store(Request $request)
    {
        $data = $request->validate(['file' => 'required|file'] + $this->metadataRules());
        if ($refusal = $this->appReleasesMustBeFull($data)) {
            return $refusal;
        }

        $file = $request->file('file');
        $filename = $this->safeFilename($file->getClientOriginalName());
        $token = Str::random(40);
        $path = $file->storeAs("device-releases/{$token}", $filename, 'local');
        $release = $this->createRelease($data, $path, $token, $filename, $file->getSize());

        return response()->json(['message' => 'Release uploaded.', 'record' => $release], 201);
    }

    // ---- Chunked upload -------------------------------------------------------------------
    // Firmware is ~1 GB: far over PHP's post_max_size and a proxy's body limit, and a flaky
    // connection shouldn't cost the whole transfer. The client creates an upload (metadata +
    // total size), PUTs consecutive raw chunks at explicit offsets (an out-of-order or repeated
    // chunk is refused with the server's real offset, so a client can always resume), then
    // completes it. Bytes accumulate in a private part file owned by the uploading person.

    private function uploads(): ChunkedUpload
    {
        return new ChunkedUpload(Storage::disk('local')->path('device-uploads'));
    }

    /** The upload's metadata, only for the person who started it. */
    private function loadUpload(string $id): array
    {
        $meta = $this->uploads()->meta($id);
        abort_unless(($meta['uploaded_by'] ?? null) === Auth::id(), 404);

        return $meta;
    }

    public function startUpload(Request $request)
    {
        $data = $request->validate([
            'filename' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:'.config('devices.max_release_bytes'),
        ] + $this->metadataRules());
        if ($refusal = $this->appReleasesMustBeFull($data)) {
            return $refusal;
        }

        $id = $this->uploads()->create([
            'metadata' => collect($data)->only(array_keys($this->metadataRules()))->all(),
            'filename' => $this->safeFilename($data['filename']),
            'size' => $data['size'],
            'uploaded_by' => Auth::id(),
        ]);

        return response()->json(['upload_id' => $id, 'chunk_size' => config('devices.upload_chunk_bytes'), 'received' => 0], 201);
    }

    public function uploadStatus(string $id)
    {
        $meta = $this->loadUpload($id);

        return response()->json(['received' => $this->uploads()->received($id), 'size' => $meta['size']]);
    }

    public function uploadChunk(Request $request, string $id)
    {
        $this->loadUpload($id);
        [$status, $payload] = $this->uploads()->append($id, (int) $request->query('offset', -1), $request->getContent());

        return response()->json($payload, $status);
    }

    public function finishUpload(string $id)
    {
        $meta = $this->loadUpload($id);
        $uploads = $this->uploads();
        $received = $uploads->received($id);
        if ($received !== $meta['size']) {
            return response()->json(['message' => "Upload is incomplete ({$received} of {$meta['size']} bytes).", 'received' => $received], 409);
        }

        $disk = Storage::disk('local');
        $token = Str::random(40);
        $path = "device-releases/{$token}/{$meta['filename']}";
        $disk->makeDirectory("device-releases/{$token}");
        rename($uploads->partPath($id), $disk->path($path));
        $uploads->delete($id);

        $release = $this->createRelease($meta['metadata'], $path, $token, $meta['filename'], $meta['size']);

        return response()->json(['message' => 'Release uploaded.', 'record' => $release], 201);
    }

    public function cancelUpload(string $id)
    {
        $this->loadUpload($id);
        $this->uploads()->delete($id);

        return response()->json(['message' => 'Upload cancelled.']);
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
