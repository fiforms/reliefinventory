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

    // Docker/R Station only: what the `scheduler` container's daily backup does. Set per deployment in
    // docker/compose.yaml (the scheduler and pre-migration scripts read the same RI_* variables); the app
    // only reads them to show the admin what is configured, since a container's schedule is not editable
    // from inside the app. `scope` is what the daily and pre-migration backups include: database | full.
    'container' => [
        'enabled' => filter_var(env('RI_BACKUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'hour' => (int) env('RI_BACKUP_HOUR', 2),
        'premigrate' => filter_var(env('RI_PREMIGRATE_BACKUP', true), FILTER_VALIDATE_BOOLEAN),
        'scope' => env('RI_BACKUP_SCOPE', 'database'),
    ],

    // Restore (first-run wizard). The upload is chunked like firmware; a proxy's request-body limit
    // (Caddy's is 64 MB, nginx's default 1 MB) is why chunks are small.
    'upload_chunk_bytes' => (int) env('RI_RESTORE_CHUNK_BYTES', 1_000_000),
    'max_restore_bytes' => (int) env('RI_RESTORE_MAX_BYTES', 64 * 1024 * 1024 * 1024),

    // Columns holding values encrypted with the warehouse's key, WAREHOUSE_KEY (MULTI_WAREHOUSE.md §5) ("table.column" => what an admin would call it). A backup
    // never carries the key, so a restore onto an instance with a different key can't read these; RestoreService
    // blanks whatever it can't decrypt and lists the labels so the admin can re-enter them. A test fails if a
    // model gains an `encrypted` cast that is not registered here.
    'encrypted_columns' => [
        'mail_settings.resend_key' => 'Resend API key (Email Settings)',
        'mail_settings.smtp_password' => 'SMTP password (Email Settings)',
        'federation_identities.private_key' => 'Federation identity key (peers must re-approve a new one)',
    ],
];
