<?php

namespace App\Support\Atlas\Import;

final class BoundaryImportResult
{
    public int $featuresRead = 0;

    public int $matchedSettlements = 0;

    public int $inserted = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $unmatchedFeatures = 0;

    public int $settlementsWithoutBoundaries = 0;

    public int $invalidGeometries = 0;

    public int $ambiguousMatches = 0;

    /** @var list<string> */
    public array $missingSettlements = [];

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
