<?php

// Per-warehouse files and secrets (MULTI_WAREHOUSE.md §2, §11). A relative path is taken from the
// project root, so tests can point both somewhere disposable.
$path = fn (?string $value, string $default) => $value === null || $value === ''
    ? $default
    : (str_starts_with($value, '/') ? $value : base_path($value));

return [

    // <storage_dir>/<id>/files/ is the warehouse's `local` disk: its uploads and nothing else.
    'storage_dir' => $path(env('RI_WAREHOUSE_STORAGE_DIR'), storage_path('warehouses')),

    // <secrets_dir>/<id>/warehouse.key (and later its database password): never inside a Storage disk
    // root or a backup, so neither a file route nor a backup can reach them. Docker: its own volume.
    'secrets_dir' => $path(env('RI_WAREHOUSE_SECRETS_DIR'), base_path('secrets/warehouses')),

    // Per-warehouse database users and schemas (MULTI_WAREHOUSE.md §7). Only the provisioner (a separate process holding the
    // administrator credential) ever uses `admin_*`; the web, queue and scheduler containers do not mount it.
    'provisioning' => [
        // The connection whose host/port/charset the warehouse connections copy.
        'base_connection' => env('DB_CONNECTION', 'mariadb'),
        // A warehouse's schema is `<prefix>_<id, lower case>`; the prefix is the instance's own database name.
        'schema_prefix' => env('RI_WAREHOUSE_SCHEMA_PREFIX', env('DB_DATABASE', 'reliefinventory')),
        'admin_user' => env('RI_DB_ADMIN_USER', 'root'),
        'admin_password_file' => env('RI_DB_ROOT_PASSWORD_FILE', '/secrets/db_root_password'),
        'admin_password' => env('RI_DB_ADMIN_PASSWORD'),
        // Where a warehouse's user may connect from. The Docker network is private; a test on one machine sets `localhost`.
        'user_host' => env('RI_WAREHOUSE_DB_USER_HOST', '%'),
        'max_user_connections' => (int) env('RI_WAREHOUSE_DB_MAX_CONNECTIONS', 20),
        // How long `instance:migrate` waits for the provisioner to answer a command, in seconds.
        'wait_seconds' => (int) env('RI_PROVISIONER_WAIT_SECONDS', 120),
    ],

];
