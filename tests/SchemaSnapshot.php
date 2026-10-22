<?php

namespace Tests;

use App\Services\DatabaseTools;
use Illuminate\Support\Facades\File;

/**
 * Running every migration from scratch takes ~50s on MariaDB (hundreds of DDL statements, each synced
 * to disk), and each parallel worker plus every RestoreTest rebuild paid it. Instead, the first
 * from-scratch migrate dumps the result — schema AND data, since many migrations insert lookup rows that
 * `schema:dump` would leave out — to a snapshot keyed by a hash of the migration files, and every later
 * `migrate:fresh` loads that instead. Adding or editing a migration changes the hash, so the snapshot
 * rebuilds itself on the next run. See RefreshDatabaseFromSnapshot.
 */
class SchemaSnapshot
{
    /** Options for `migrate:fresh` that load the snapshot when there is one. */
    public static function freshOptions(): array
    {
        $path = self::path();

        return ['--force' => true] + (is_file($path) ? ['--schema-path' => $path] : []);
    }

    public static function saveIfMissing(): void
    {
        $path = self::path();
        if (is_file($path)) {
            return;
        }

        $tools = app(DatabaseTools::class);
        $tmp = $path.'.'.getmypid().'.tmp';   // parallel workers may all build it at once; rename() is atomic
        File::ensureDirectoryExists(dirname($path));
        $tools->process($tools->dumpCommand($tmp))->run();

        if ($tools->dumpIsComplete($tmp)) {
            foreach (File::glob(dirname($path).'/schema-snapshot-*.sql') as $old) {
                @unlink($old);
            }
            rename($tmp, $path);
        } else {
            @unlink($tmp);   // no snapshot just means the next run migrates from scratch again
        }
    }

    private static function path(): string
    {
        static $path;
        if ($path) {
            return $path;
        }

        $hash = hash_init('md5');
        foreach (File::glob(database_path('migrations/*.php')) as $file) {
            hash_update($hash, basename($file).md5_file($file));
        }

        return $path = storage_path('framework/testing/schema-snapshot-'.hash_final($hash).'.sql');
    }
}
