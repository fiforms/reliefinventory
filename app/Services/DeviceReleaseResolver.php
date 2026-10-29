<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use App\Models\DeviceRelease;
use App\Models\ManagedDevice;

/**
 * Decides which release (if any) a heartbeat offers a device, per kind —
 * the "Resolution" and "Versions" sections of DEVICE_CONTRACT.md.
 */
class DeviceReleaseResolver
{
    /**
     * Versions are X.Y.Z or <platform X.Y.Z>_<product X.Y.Z> (a plain X.Y.Z
     * counts as product 0.0.0); compared as six integers, platform first.
     * Only the leading version is parsed (device app versions carry a git
     * hash suffix). Unparseable strings fall back to version_compare().
     */
    public static function compareVersions(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa === null || $pb === null) {
            return version_compare($a, $b);
        }

        return $pa <=> $pb;
    }

    private static function parse(string $version): ?array
    {
        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)(?:_(\d+)\.(\d+)\.(\d+))?/', $version, $m)) {
            return null;
        }

        return array_map('intval', array_pad(array_slice($m, 1), 6, 0));
    }

    /** The release to offer for $kind, or null when there's nothing newer. */
    public function offerFor(ManagedDevice $device, string $kind): ?DeviceRelease
    {
        if ($device->device_type !== 'rstation') {
            return null; // print servers don't take firmware
        }

        return $this->offerForState(
            $device->architecture, $device->update_channel, $kind === 'app' ? $device->app_version : $device->os_version, $kind);
    }

    /** The same resolution for a bare (architecture, channel, installed version) — what a device asking a source server sends. */
    public function offerForState(?string $architecture, string $channel, ?string $current, string $kind): ?DeviceRelease
    {
        if (! $architecture) {
            return null; // first-ever heartbeat: matches no release
        }

        $tagged = DeviceRelease::where('kind', $kind)
            ->where('architecture', $architecture)
            ->whereIn('release_type', ['full', 'hotfix'])
            ->get()
            ->filter(fn (DeviceRelease $r) => $r->isTaggedOn($channel));

        // Hotfix first: exact byte-for-byte match on the device's reported version.
        if ($current !== null) {
            $hotfix = $tagged->first(fn ($r) => $r->release_type === 'hotfix' && $r->required_base_version === $current);
            if ($hotfix) {
                return $hotfix->version !== $current ? $hotfix : null;
            }
        }

        $full = $tagged->first(fn ($r) => $r->release_type === 'full');
        if (! $full) {
            return null;
        }

        return $current === null || self::compareVersions($full->version, $current) > 0 ? $full : null;
    }

    /**
     * Tag a release onto a channel, evicting the sibling in the same slot:
     * (kind, architecture, channel) for full, plus one slot per
     * required_base_version for hotfixes. disk_image and vm_image are never offered over
     * the heartbeat but is tagged the same way, one per slot.
     */
    public function tag(DeviceRelease $release, string $channel): void
    {
        DeviceRelease::where('kind', $release->kind)
            ->where('architecture', $release->architecture)
            ->where('release_type', $release->release_type)
            ->where('id', '!=', $release->id)
            ->when($release->release_type === 'hotfix', fn ($q) => $q->where('required_base_version', $release->required_base_version))
            ->get()
            ->filter(fn (DeviceRelease $r) => $r->isTaggedOn($channel))
            ->each(fn (DeviceRelease $r) => $r->update(['channels' => array_values(array_diff($r->channels, [$channel]))]));

        $release->update(['channels' => array_values(array_unique([...($release->channels ?? []), $channel]))]);
    }

    public function untag(DeviceRelease $release, string $channel): void
    {
        $release->update(['channels' => array_values(array_diff($release->channels ?? [], [$channel]))]);
    }
}
