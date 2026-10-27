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

];
