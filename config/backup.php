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

    // Backups are one at a time across the whole instance (MULTI_WAREHOUSE.md §10/§11): the scheduler asks every warehouse for its daily
    // backup, and N dumps at once would all hit the database together. One that finds another running waits up to this many seconds.
    'lock_wait_seconds' => (int) env('RI_BACKUP_LOCK_WAIT', 3600),

    // Tables that hold only what is live right now, so a warehouse's dump keeps their structure but not their rows (mariadb-dump
    // --ignore-table-data; a clone of a backup would otherwise start with working copies of the original's logged-in sessions), and a
    // restore empties them (which also covers backups made before this list). A test fails if one of these doesn't exist.
    'live_state_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'],

    // The php binary used to start the detached backup process. Not PHP_BINARY: under FPM/FrankenPHP
    // that is the server, not the CLI.
    'php_binary' => env('RI_PHP_BINARY', 'php'),

    // Extra flags for the dump binary. Null = pick by binary: --skip-ssl for mariadb-dump (the MariaDB 11
    // client otherwise insists on verified TLS, which the compose database doesn't offer), none for mysqldump.
    'dump_args' => env('RI_DB_DUMP_ARGS'),

    // A queued/running backup whose process is gone is marked failed once it is this many seconds old.
    'stale_after_seconds' => 120,

    // The installation-wide defaults for scheduled snapshots, from the environment (docker/compose.yaml, or a bare-metal .env). Since the
    // per-warehouse schedule (App\Models\BackupSchedule, Warehouse Backups page) only `enabled` still acts on its own: false stops every
    // scheduled snapshot on the installation whatever the warehouses chose. `hour` and `scope` only seed a warehouse's schedule row the
    // first time it is created. `premigrate` and `scope` are also what the pre-upgrade backup uses. (The key is `container` for history.)
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
