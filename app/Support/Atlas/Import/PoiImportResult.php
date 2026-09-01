<?php

namespace App\Support\Atlas\Import;

final class PoiImportResult
{
    public int $recordsRead = 0;

    public int $classified = 0;

    public int $unclassified = 0;

    public int $inserted = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $deactivated = 0;

    public int $unnamed = 0;

    public int $municipalityAssigned = 0;

    public int $settlementAssigned = 0;

    public int $ambiguous = 0;

    public int $unassigned = 0;

    public int $failed = 0;

    /** @var array<string, int> */
    public array $byFamily = [];

    /** @var array<string, int> */
    public array $byType = [];

    /** @var list<array<string, mixed>> */
    public array $unclassifiedSamples = [];

    /** @var list<array<string, mixed>> */
    public array $qaRows = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $errors = [];

    public bool $ok = true;

    public ?string $sourceHash = null;

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
