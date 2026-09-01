<?php

namespace App\Support\Atlas\Import;

final class SettlementImportResult
{
    public int $recordsRead = 0;

    public int $recordsInserted = 0;

    public int $recordsUpdated = 0;

    public int $recordsSkipped = 0;

    public int $outageLinked = 0;

    public int $outageNotFound = 0;

    public int $outageAmbiguous = 0;

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $errors = [];

    public bool $ok = true;

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
