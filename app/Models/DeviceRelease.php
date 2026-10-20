<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An uploaded kiosk app archive (.tar.gz) or OS bundle (.raucb), stored on
 * the local (private) disk and served by DeviceArtifactController. `channels`
 * lists where it's currently tagged; see DeviceReleaseResolver for the slot
 * rules (one full release per kind/arch/channel, one hotfix per base version).
 */
class DeviceRelease extends Model
{
    /** Platform keys (stored in `architecture`) for the RI Print Client, with display names. */
    public const PRINT_CLIENT_PLATFORMS = [
        'windows' => 'Windows',
        'macos-arm64' => 'Mac (Apple Silicon)',
        'macos-x64' => 'Mac (Intel)',
        'linux-x64' => 'Linux (x64)',
        'linux-arm64' => 'Linux (arm64)',
    ];

    /** Release types that are only ever downloaded by people (never offered to devices). */
    public const IMAGE_TYPES = ['disk_image', 'vm_image'];

    protected $fillable = [
        'kind', 'version', 'architecture', 'release_type', 'required_base_version', 'sha256',
        'storage_path', 'filename', 'size_bytes', 'download_token', 'channels', 'notes', 'uploaded_by',
    ];

    protected $casts = ['channels' => 'array'];

    protected $hidden = ['storage_path', 'download_token'];

    protected $appends = ['download_url'];

    /**
     * Raspberry Pi SD card images, VM images and the print client are for people, not devices:
     * no heartbeat offers them, and they are served only to signed-in people who can pair
     * devices (DeviceDownloadController) — not by the unauthenticated token route.
     */
    public function isDownloadOnly(): bool
    {
        return $this->kind === 'print_client' || in_array($this->release_type, self::IMAGE_TYPES, true);
    }

    public function getDownloadUrlAttribute(): string
    {
        if ($this->isDownloadOnly()) {
            return url("/json/device-downloads/{$this->id}/".rawurlencode($this->filename));
        }

        return url("/api/devices/artifacts/{$this->download_token}/".rawurlencode($this->filename));
    }

    public function isTaggedOn(string $channel): bool
    {
        return in_array($channel, $this->channels ?? [], true);
    }
}
