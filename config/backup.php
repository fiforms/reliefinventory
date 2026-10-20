<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

return [
    // Where on-demand backup zips are written. Deliberately NOT under storage/app (update.sh tars that
    // directory, so a zip stored there would be swallowed by every later backup). The Docker compose
    // file points this at the ./backups bind mount shared by the web, queue and scheduler containers.
    'dir' => env('RI_BACKUP_DIR', storage_path('backups')),

    // A finished zip is deleted this many days after it was made (an admin can delete sooner).
    'keep_days' => (int) env('RI_BACKUP_KEEP_DAYS', 14),

    // Directories under the app's storage disks that "include uploads" copies. Paths are relative to
    // the disk root (storage/app/private, storage/app/public). Excluded by default: print-jobs
    // (rendered PDFs, regenerated on demand), device-releases / device-uploads (firmware images —
    // gigabytes, and re-uploadable). Never add .app_key or .env here: BackupService refuses them anyway.
    'exclude_dirs' => ['print-jobs', 'device-releases', 'device-uploads'],

    // The php binary used to start the detached backup process. Not PHP_BINARY: under FPM/FrankenPHP
    // that is the server, not the CLI.
    'php_binary' => env('RI_PHP_BINARY', 'php'),

    // Extra flags for the dump binary. Null = pick by binary: --skip-ssl for mariadb-dump (the MariaDB 11
    // client otherwise insists on verified TLS, which the compose database doesn't offer), none for mysqldump.
    'dump_args' => env('RI_DB_DUMP_ARGS'),

    // A queued/running backup whose process is gone is marked failed once it is this many seconds old.
    'stale_after_seconds' => 120,
];
