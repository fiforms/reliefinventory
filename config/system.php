<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [

    /*
    |--------------------------------------------------------------------------
    | System administration (updates + backups)
    |--------------------------------------------------------------------------
    |
    | The bare-metal software update unit used by the System Administration
    | panel (deprecated; Docker installs update through the host-side updater).
    | The unit is installed from scripts/systemd/ and triggered through a
    | narrow sudoers rule (scripts/systemd/reliefinventory-sudoers) — the web
    | app can start exactly that unit and nothing else. Backups are not here:
    | see config/backup.php and dev/notes/BACKUPS.md.
    |
    */

    // Which kind of install this is: 'docker' (the container image, incl. the R Station local server) or
    // 'systemd' (a bare-metal install updated by scripts/update.sh). The admin page shows only what the
    // platform can do — container installs have no systemd units, git checkout or tiered backup timer.
    // The Dockerfile sets RI_RUNTIME=docker; /.dockerenv is the fallback for a hand-rolled container.
    'runtime' => env('RI_RUNTIME') ?: (is_file('/.dockerenv') ? 'docker' : 'systemd'),

    // Docker self-update (docker/updater/ri-updater.py on the host): the shared secret the updater presents. Empty = off.
    'update_secret' => env('RI_UPDATE_SECRET'),

    // The old bare-metal backup panel's settings file. Nothing reads it any more except the one migration that carries its schedule into
    // the backup_schedule table (App\Support\LegacyBackupSettings); safe to delete once every install has migrated.
    'settings_file' => env('SYSTEM_BACKUP_SETTINGS_FILE', storage_path('app/backup-settings.conf')),

    'update_status_file' => env('SYSTEM_UPDATE_STATUS_FILE', storage_path('app/system-update-status.json')),

    'update_unit' => env('SYSTEM_UPDATE_UNIT', 'reliefinventory-update.service'),

];
