<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [

    /*
    |--------------------------------------------------------------------------
    | System administration (updates + backups)
    |--------------------------------------------------------------------------
    |
    | Paths and systemd unit names used by the System Administration panel.
    | The units are installed from scripts/systemd/ and triggered through a
    | narrow sudoers rule (scripts/systemd/reliefinventory-sudoers) — the web
    | app can start exactly those two units and nothing else.
    |
    */

    'backup_dir' => env('SYSTEM_BACKUP_DIR', '/var/backups/reliefinventory'),

    'settings_file' => env('SYSTEM_BACKUP_SETTINGS_FILE', storage_path('app/backup-settings.conf')),

    'update_status_file' => env('SYSTEM_UPDATE_STATUS_FILE', storage_path('app/system-update-status.json')),

    'update_unit' => env('SYSTEM_UPDATE_UNIT', 'reliefinventory-update.service'),

    'backup_now_unit' => env('SYSTEM_BACKUP_NOW_UNIT', 'reliefinventory-backup-now.service'),

];
