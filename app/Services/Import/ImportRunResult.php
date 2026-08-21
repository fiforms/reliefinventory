<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Services\Import;

/**
 * The result of one Importer::process() pass, whether a dry-run Preview or
 * a real Commit — same shape either way, so the /setup/import UI renders
 * both identically. `rows` are plain arrays ready to become ImportBatchRow
 * records; `decisions` flags ambiguous mappings (e.g. an unrecognized
 * package-type/category translation) that need a human's confirmation
 * before Commit should be trusted, without blocking Preview itself.
 */
class ImportRunResult
{
    /** @var array<int, array> */
    public array $rows = [];

    /** @var array<int, string> */
    public array $decisions = [];

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $errored = 0;

    public function addRow(int $rowNumber, ?string $sourceKey, string $outcome, ?string $errorMessage, array $rawRow, ?string $mappedEntityType = null, ?int $mappedEntityId = null): void
    {
        $this->rows[] = [
            'row_number' => $rowNumber,
            'source_key' => $sourceKey,
            'outcome' => $outcome,
            'error_message' => $errorMessage,
            'raw_row' => $rawRow,
            'mapped_entity_type' => $mappedEntityType,
            'mapped_entity_id' => $mappedEntityId,
        ];

        match ($outcome) {
            'created' => $this->created++,
            'updated' => $this->updated++,
            'skipped' => $this->skipped++,
            'error' => $this->errored++,
            default => null,
        };
    }

    public function flagDecision(string $message): void
    {
        if (! in_array($message, $this->decisions, true)) {
            $this->decisions[] = $message;
        }
    }

    public function summary(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errored' => $this->errored,
        ];
    }
}
