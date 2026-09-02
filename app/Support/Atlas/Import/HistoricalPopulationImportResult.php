<?php

namespace App\Support\Atlas\Import;

final class HistoricalPopulationImportResult
{
    public int $recordsRead = 0;

    public int $mappedExact = 0;

    public int $mappedWithCaveat = 0;

    public int $ambiguous = 0;

    public int $unmatched = 0;

    public int $inserted = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $rejected = 0;

    public int $skipped = 0;

    public int $conflicts = 0;

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<array<string, mixed>> */
    public array $qaRows = [];

    public bool $ok = true;

    public ?string $artifactHash = null;

    public ?string $mappingHash = null;

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function addError(string $message): void
    {
        $this->ok = false;
        $this->errors[] = $message;
    }
}
