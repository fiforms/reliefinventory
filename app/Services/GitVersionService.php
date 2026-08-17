<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Reads the currently-deployed commit from git — the same technique
 * SystemController::versionInfo() uses for the System Administration
 * panel, pulled out so other callers (feedback reports) don't duplicate
 * the Process::run plumbing. There is no separate release-version scheme
 * yet; the short commit hash is the only version identifier the app has.
 */
class GitVersionService
{
    public function currentCommit(): ?string
    {
        $result = Process::path(base_path())->timeout(5)->run(['git', 'rev-parse', '--short=9', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }
}
