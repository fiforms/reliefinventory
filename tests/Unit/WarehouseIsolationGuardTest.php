<?php

// MULTI_WAREHOUSE.md §13: everything a warehouse owns is reached through the switched default connection and
// its `local` disk. A literal connection name or a raw path into storage/app would quietly reach the instance's
// (or another warehouse's) data, so this fails on any outside the places allowed to use one.

/** @return array<string, string> relative path => contents, for every PHP file under app/ */
function appSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

test('only the instance-level code names a database connection', function () {
    // The control and accounts schemas, by their own models, the login code, and the services that bridge them.
    $allowed = [
        'control' => ['app/Models/Control/'],
        'accounts' => ['app/Models/Accounts/', 'app/Services/Simulator/WarehouseSimulator.php'],
    ];

    $offenders = [];
    foreach (appSources() as $path => $source) {
        preg_match_all('/(?:connection\(\s*|\$connection\s*=\s*)[\'"]([A-Za-z0-9_]+)[\'"]/', $source, $m);
        foreach ($m[1] as $name) {
            $ok = collect($allowed[$name] ?? [])->contains(fn ($prefix) => str_starts_with($path, $prefix));
            if (! $ok) {
                $offenders[] = "{$path}: connection '{$name}'";
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('no code reaches into storage/app, a public disk, or the instance key for warehouse data', function () {
    $allowed = ['app/Services/Warehouses/WarehouseLayout.php'];   // moves the old storage/app/private, once

    $offenders = [];
    foreach (appSources() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }
        if (preg_match('/storage_path\(\s*[\'"]app/', $source)) {
            $offenders[] = "{$path}: storage_path('app…')";
        }
        if (preg_match('/Storage::disk\(\s*[\'"]public[\'"]|disks\.public/', $source)) {
            $offenders[] = "{$path}: the public disk";
        }
        // Crypt is APP_KEY (instance); a warehouse's data is encrypted with Model::currentEncrypter() (SwitchKey).
        if (preg_match('/\bCrypt::|(?<![>:\w$])(?:en|de)crypt(?:String)?\(/', $source)) {
            $offenders[] = "{$path}: Crypt / encrypt() (use Model::currentEncrypter() for warehouse data)";
        }
    }

    expect($offenders)->toBe([]);
});
