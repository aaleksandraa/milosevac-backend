<?php

namespace App\Enums;

enum CensusComparability: string
{
    case Comparable = 'comparable';
    case ComparableWithCaveat = 'comparable_with_caveat';
    case NotDirectlyComparable = 'not_directly_comparable';
    case Unresolved = 'unresolved';

    public function isDirectlyComparable(): bool
    {
        return $this === self::Comparable;
    }

    public function mayComputeChange(): bool
    {
        return $this === self::Comparable || $this === self::ComparableWithCaveat;
    }
}
