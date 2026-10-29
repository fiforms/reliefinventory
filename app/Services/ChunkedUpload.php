<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Resumable, offset-checked chunked upload storage, shared by firmware releases
 * (DeviceReleaseController) and backup restore (RestoreController).
 *
 * Protocol (the HTTP side belongs to each controller): create an upload with a size, append
 * consecutive raw chunks at explicit offsets — an out-of-order or repeated chunk is refused with
 * the server's real offset, so a client can always resume — then take the finished file.
 * Each upload is a directory <base>/<40-char id>/ holding meta.json (the caller's metadata plus
 * `size`) and data.part (the bytes so far). Ownership/permission checks are the caller's job:
 * the id is an unguessable capability, and meta() hands back whatever the caller stored.
 */
class ChunkedUpload
{
    public function __construct(private string $baseDir) {}

    public function dir(string $id): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $id) === 1, 404);

        return rtrim($this->baseDir, '/')."/{$id}";
    }

    public function partPath(string $id): string
    {
        return $this->dir($id).'/data.part';
    }

    /** @param  array  $meta  caller's metadata; must include integer `size`. @return string the new upload id */
    public function create(array $meta): string
    {
        $this->pruneAbandoned();
        $id = Str::random(40);
        $dir = $this->dir($id);
        @mkdir($dir, 0750, true);
        file_put_contents("{$dir}/meta.json", json_encode($meta));
        touch("{$dir}/data.part");

        return $id;
    }

    /** The stored metadata, or a 404 if there is no such upload. */
    public function meta(string $id): array
    {
        $file = $this->dir($id).'/meta.json';
        abort_unless(is_file($file), 404);

        return json_decode(file_get_contents($file), true);
    }

    public function received(string $id): int
    {
        $path = $this->partPath($id);
        clearstatcache(true, $path);

        return is_file($path) ? filesize($path) : 0;
    }

    /**
     * Append a chunk at $offset. @return array{0: int, 1: array} HTTP status and JSON payload.
     */
    public function append(string $id, int $offset, string $body): array
    {
        $meta = $this->meta($id);
        $path = $this->partPath($id);

        $handle = fopen($path, 'cb');
        flock($handle, LOCK_EX);
        try {
            clearstatcache(true, $path);
            $received = filesize($path);
            if ($offset !== $received) {
                return [409, ['message' => 'Chunk is out of sequence.', 'received' => $received]];
            }
            if ($body === '' || $received + strlen($body) > $meta['size']) {
                return [422, ['message' => 'Chunk is empty or larger than the declared file.', 'received' => $received]];
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $body);
            fflush($handle);
            $received += strlen($body);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        touch($this->dir($id).'/meta.json');

        return [200, ['received' => $received, 'size' => $meta['size']]];
    }

    public function isComplete(string $id): bool
    {
        return $this->received($id) === $this->meta($id)['size'];
    }

    public function delete(string $id): void
    {
        $dir = $this->dir($id);
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** Abandoned uploads would otherwise sit on disk forever. */
    private function pruneAbandoned(int $days = 2): void
    {
        foreach (glob(rtrim($this->baseDir, '/').'/*/meta.json') ?: [] as $meta) {
            if (filemtime($meta) < now()->subDays($days)->timestamp) {
                $this->delete(basename(dirname($meta)));
            }
        }
    }
}
