<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Reads the currently-deployed commit from git — the same technique
 * SystemController::versionInfo() uses for the System Administration
 * panel, pulled out so other callers (feedback reports) don't duplicate
 * the Process::run plumbing. The release version is the GitHub Release tag:
 * baked in as RI_VERSION by the Docker build, or `git describe --tags` on a
 * source install. Nothing is stored in a file, so it can't go stale; with
 * neither available the app is simply unversioned (commit only).
 */
class GitVersionService
{
    /**
     * Release version, e.g. "1.4.0" (a Docker release image) or "1.4.0-12-gabc1234" (a source
     * checkout 12 commits past that release's tag); null when unknown. Leading "v" is dropped.
     */
    public function release(): ?string
    {
        if ($baked = config('app.version')) {
            return ltrim(trim($baked), 'vV');
        }

        $result = Process::path(base_path())->timeout(5)->run(['git', 'describe', '--tags', '--match', 'v[0-9]*']);

        return $result->successful() && trim($result->output()) !== '' ? ltrim(trim($result->output()), 'vV') : null;
    }

    public function currentCommit(): ?string
    {
        // A Docker image has no .git — its build bakes the commit in instead.
        if ($baked = config('app.git_commit')) {
            return substr($baked, 0, 9);
        }

        $result = Process::path(base_path())->timeout(5)->run(['git', 'rev-parse', '--short=9', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }
}
