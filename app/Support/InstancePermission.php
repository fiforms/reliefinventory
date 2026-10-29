<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Support;

/**
 * What an instance-level trust (App\Models\Control\InstanceTrust) may do. Deliberately not in TrustPermission, the per-warehouse catalogue:
 * a warehouse's Trust Console must never be able to offer, or grant, a power over the whole instance.
 */
final class InstancePermission
{
    /** List, make and download whole-instance backups (every warehouse and the shared databases). */
    public const BACKUP = 'instance.backup';

    public const LABEL = 'Instance backup';

    public const DESCRIPTION = 'Make and download backups of the WHOLE instance: every warehouse and the shared databases (so every person\'s information). It cannot restore anything or change any setting.';

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::BACKUP];
    }

    /** @return list<string> only the keys this catalogue knows, once each */
    public static function clean(array $permissions): array
    {
        return array_values(array_unique(array_intersect($permissions, self::keys())));
    }
}
