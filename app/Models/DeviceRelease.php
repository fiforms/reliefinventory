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
    protected $fillable = [
        'kind', 'version', 'architecture', 'release_type', 'required_base_version', 'sha256',
        'storage_path', 'filename', 'size_bytes', 'download_token', 'channels', 'notes', 'uploaded_by',
    ];

    protected $casts = ['channels' => 'array'];

    protected $hidden = ['storage_path', 'download_token'];

    protected $appends = ['download_url'];

    public function getDownloadUrlAttribute(): string
    {
        return url("/api/devices/artifacts/{$this->download_token}/".rawurlencode($this->filename));
    }

    public function isTaggedOn(string $channel): bool
    {
        return in_array($channel, $this->channels ?? [], true);
    }
}
