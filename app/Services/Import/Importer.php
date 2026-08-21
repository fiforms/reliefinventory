<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

/**
 * One implementation per external file type (source+file_type pair), not
 * per source system — e.g. Flowtrac's Contacts.csv and Products.csv each
 * get their own Importer, since their row shapes and target models are
 * unrelated even though both come from "flowtrac". process() is the one
 * place matching/write logic lives: called with $commit=false for Preview
 * (dry run — no app-data writes) and $commit=true for Commit (real
 * writes), so the two passes can never disagree about what would happen.
 */
interface Importer
{
    /**
     * The source system this importer belongs to, e.g. "flowtrac".
     */
    public function source(): string;

    /**
     * The file type this importer handles, e.g. "flowtrac_contacts" — one
     * of these per upload; matches ImportBatch::file_type.
     */
    public function fileType(): string;

    /**
     * Parse $absolutePath and either report what would happen ($commit is
     * false) or actually write it ($commit is true). Must be safe to call
     * repeatedly with $commit=true against the same file without
     * duplicating data — match/upsert via source_system/source_ref, never
     * blind insert.
     */
    public function process(string $absolutePath, bool $commit): ImportRunResult;
}
