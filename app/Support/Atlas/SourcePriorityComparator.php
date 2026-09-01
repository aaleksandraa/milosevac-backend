<?php

namespace App\Support\Atlas;

use App\Enums\VerificationStatus;
use App\Models\DataSource;

/**
 * Deterministic source precedence. Higher authority_rank wins.
 * Newest import timestamps are never considered.
 */
final class SourcePriorityComparator
{
    public function compare(
        DataSource $left,
        DataSource $right,
        ?VerificationStatus $leftVerification = null,
        ?VerificationStatus $rightVerification = null,
        ?string $leftDatasetVersion = null,
        ?string $rightDatasetVersion = null,
    ): int {
        $rank = $right->authority_rank <=> $left->authority_rank;
        if ($rank !== 0) {
            return $rank;
        }

        $verification = $this->verificationStrength($rightVerification)
            <=> $this->verificationStrength($leftVerification);
        if ($verification !== 0) {
            return $verification;
        }

        if ($leftDatasetVersion !== null && $rightDatasetVersion !== null && $leftDatasetVersion !== $rightDatasetVersion) {
            return strcmp($rightDatasetVersion, $leftDatasetVersion);
        }

        return 0;
    }

    public function prefers(
        DataSource $left,
        DataSource $right,
        ?VerificationStatus $leftVerification = null,
        ?VerificationStatus $rightVerification = null,
    ): bool {
        return $this->compare($left, $right, $leftVerification, $rightVerification) < 0;
    }

    private function verificationStrength(?VerificationStatus $status): int
    {
        return $status?->strength() ?? 0;
    }
}
