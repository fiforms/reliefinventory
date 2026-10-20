<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Http\Controllers;

use App\Models\DeviceRelease;
use App\Models\FirmwareSetting;
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
        return response()->json(['records' => DeviceRelease::orderByDesc('created_at')->get()]);
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
            'kind' => ['required', Rule::in(['app', 'os'])],
            'version' => 'required|string|max:255',
            'architecture' => 'required|string|max:255',
            'release_type' => ['required', Rule::in(['full', 'hotfix', 'disk_image'])],
            'required_base_version' => 'required_if:release_type,hotfix|nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }

    private function appReleasesMustBeFull(array $data): ?JsonResponse
    {
        if ($data['kind'] === 'app' && $data['release_type'] !== 'full') {
            return response()->json(['message' => 'App releases are always full.', 'errors' => ['release_type' => ['App releases are always full.']]], 422);
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
        return DeviceRelease::create([
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

    private function uploadDir(string $id): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $id) === 1, 404);

        return "device-uploads/{$id}";
    }

    /** The upload's metadata, only for the person who started it. */
    private function loadUpload(string $id): array
    {
        $disk = Storage::disk('local');
        $dir = $this->uploadDir($id);
        abort_unless($disk->exists("{$dir}/meta.json"), 404);
        $meta = json_decode($disk->get("{$dir}/meta.json"), true);
        abort_unless(($meta['uploaded_by'] ?? null) === Auth::id(), 404);

        return $meta;
    }

    private function receivedBytes(string $id): int
    {
        $path = Storage::disk('local')->path($this->uploadDir($id).'/data.part');

        clearstatcache(true, $path);

        return is_file($path) ? filesize($path) : 0;
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

        // Abandoned uploads would otherwise sit on disk forever.
        $disk = Storage::disk('local');
        foreach ($disk->directories('device-uploads') as $old) {
            if ($disk->lastModified($old.'/meta.json') < now()->subDays(2)->timestamp) {
                $disk->deleteDirectory($old);
            }
        }

        $id = Str::random(40);
        $disk->put($this->uploadDir($id).'/meta.json', json_encode([
            'metadata' => collect($data)->only(array_keys($this->metadataRules()))->all(),
            'filename' => $this->safeFilename($data['filename']),
            'size' => $data['size'],
            'uploaded_by' => Auth::id(),
        ]));
        touch($disk->path($this->uploadDir($id).'/data.part'));

        return response()->json(['upload_id' => $id, 'chunk_size' => config('devices.upload_chunk_bytes'), 'received' => 0], 201);
    }

    public function uploadStatus(string $id)
    {
        $meta = $this->loadUpload($id);

        return response()->json(['received' => $this->receivedBytes($id), 'size' => $meta['size']]);
    }

    public function uploadChunk(Request $request, string $id)
    {
        $meta = $this->loadUpload($id);
        $offset = (int) $request->query('offset', -1);
        $path = Storage::disk('local')->path($this->uploadDir($id).'/data.part');
        $body = $request->getContent();

        $handle = fopen($path, 'cb');
        flock($handle, LOCK_EX);
        try {
            clearstatcache(true, $path);
            $received = filesize($path);
            if ($offset !== $received) {
                return response()->json(['message' => 'Chunk is out of sequence.', 'received' => $received], 409);
            }
            if ($body === '' || $received + strlen($body) > $meta['size']) {
                return response()->json(['message' => 'Chunk is empty or larger than the declared file.', 'received' => $received], 422);
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $body);
            fflush($handle);
            $received += strlen($body);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        touch(Storage::disk('local')->path($this->uploadDir($id).'/meta.json'));

        return response()->json(['received' => $received, 'size' => $meta['size']]);
    }

    public function finishUpload(string $id)
    {
        $meta = $this->loadUpload($id);
        $received = $this->receivedBytes($id);
        if ($received !== $meta['size']) {
            return response()->json(['message' => "Upload is incomplete ({$received} of {$meta['size']} bytes).", 'received' => $received], 409);
        }

        $disk = Storage::disk('local');
        $token = Str::random(40);
        $path = "device-releases/{$token}/{$meta['filename']}";
        $disk->makeDirectory("device-releases/{$token}");
        rename($disk->path($this->uploadDir($id).'/data.part'), $disk->path($path));
        $disk->deleteDirectory($this->uploadDir($id));

        $release = $this->createRelease($meta['metadata'], $path, $token, $meta['filename'], $meta['size']);

        return response()->json(['message' => 'Release uploaded.', 'record' => $release], 201);
    }

    public function cancelUpload(string $id)
    {
        $this->loadUpload($id);
        Storage::disk('local')->deleteDirectory($this->uploadDir($id));

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
