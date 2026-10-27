<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

/**
 * Decides whether a backup manifest can be restored by THIS copy of the software, before anything is touched.
 * Returns a list of plain-language problems (empty = restorable). The version test is the migration list, not
 * a version string: if the backup has applied a migration this code doesn't have, it came from newer software
 * and its schema would be one this code doesn't understand. An older backup is fine — restore ends by running
 * whatever migrations it lacks. The commit hash in the manifest is informational only.
 */
class BackupCompatibility
{
    private const ENTRY_PATH = '#^(database/(db\.sql|accounts\.json)|files/(private|public)/[^\0\\\\]+)$#';

    /**
     * @param  array  $manifest  decoded manifest.json
     * @param  string[]  $zipNames  every name inside the zip
     * @param  string[]|null  $knownMigrations  migration names this code ships (null = read them from disk)
     * @param  bool  $checkVersion  false when this zip's database is not going to be used (a full zip supplying only
     *                              the files), so a newer schema in it is harmless
     * @return string[]
     */
    public function problems(array $manifest, array $zipNames, ?array $knownMigrations = null, bool $checkVersion = true): array
    {
        $problems = [];

        $format = $manifest['format'] ?? null;
        if (! is_int($format) || $format < 1) {
            return ['This does not look like a Relief Inventory backup (no valid manifest).'];
        }
        if ($format > BackupService::FORMAT) {
            return ["This backup uses a newer backup format ({$format}) than this software understands. Update the software first."];
        }
        if (($manifest['contains_app_key'] ?? false) !== false) {
            $problems[] = 'This backup claims to contain an application key, which a valid backup never does.';
        }

        $scope = $this->scope($manifest);
        if ($scope === null) {
            return array_merge($problems, ['The backup does not say what it contains (unknown scope).']);
        }

        // A files-only backup of an empty storage disk is legitimately just a manifest.
        $entries = $manifest['entries'] ?? null;
        if (! is_array($entries) || ($entries === [] && $scope !== 'storage')) {
            return array_merge($problems, ['The backup lists no files.']);
        }

        $paths = [];
        foreach ($entries as $entry) {
            $path = $entry['path'] ?? null;
            if (! is_string($path) || ! $this->safePath($path)) {
                $problems[] = 'The backup lists an unsafe or unrecognised file path: '.(is_string($path) ? substr($path, 0, 80) : '(not text)');

                continue;
            }
            if (! is_int($entry['size'] ?? null) || $entry['size'] < 0 || ! preg_match('/^[0-9a-f]{64}$/', (string) ($entry['sha256'] ?? ''))) {
                $problems[] = "The manifest entry for {$path} is malformed.";
            }
            if (isset($paths[$path])) {
                $problems[] = "The manifest lists {$path} twice.";
            }
            $paths[$path] = true;
        }
        if (BackupService::hasDatabase($scope) && ! isset($paths['database/db.sql'])) {
            $problems[] = 'The backup contains no database dump.';
        }
        if (! BackupService::hasStorage($scope) && array_filter(array_keys($paths), fn ($p) => str_starts_with($p, 'files/'))) {
            $problems[] = 'The manifest says this is a database-only backup but it lists uploaded files.';
        }
        if (! BackupService::hasDatabase($scope) && isset($paths['database/db.sql'])) {
            $problems[] = 'The manifest says this is a files-only backup but it lists a database dump.';
        }

        $inZip = array_filter($zipNames, fn ($n) => $n !== 'manifest.json' && ! str_ends_with($n, '/'));
        foreach (array_diff($inZip, array_keys($paths)) as $extra) {
            $problems[] = 'The zip contains a file the manifest does not list: '.substr($extra, 0, 80);
        }
        foreach (array_diff(array_keys($paths), $inZip) as $missing) {
            $problems[] = "The manifest lists {$missing} but the zip does not contain it.";
        }

        if (! $checkVersion || ! BackupService::hasDatabase($scope)) {
            return $problems;
        }

        $migrations = $manifest['database']['migrations'] ?? null;
        if (! is_array($migrations)) {
            $problems[] = 'The manifest has no migration list, so its version cannot be checked.';
        } else {
            $unknown = array_values(array_diff($migrations, $knownMigrations ?? $this->knownMigrations()));
            if ($unknown) {
                $problems[] = 'This backup was made by newer software than this installation ('.count($unknown)
                    .' database change(s) this version does not have, e.g. '.implode(', ', array_slice($unknown, 0, 3))
                    .'). Update the software, then restore.';
            }
        }

        return $problems;
    }

    /**
     * database | full | storage. Format-1 zips (before scopes existed) had no `scope`: they held a dump, plus
     * the files when `includes_uploads` was set. Null when the manifest names something else.
     */
    public function scope(array $manifest): ?string
    {
        $scope = $manifest['scope'] ?? (($manifest['includes_uploads'] ?? false) ? 'full' : 'database');

        return in_array($scope, BackupService::SCOPES, true) ? $scope : null;
    }

    private function safePath(string $path): bool
    {
        return preg_match(self::ENTRY_PATH, $path) === 1
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '//')
            && ! in_array('..', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true);
    }

    /** @return string[] */
    public function knownMigrations(): array
    {
        $migrator = app('migrator');

        return array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
    }
}
