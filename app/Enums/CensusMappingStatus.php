<?php

namespace App\Enums;

enum CensusMappingStatus: string
{
    case ExactIdentity = 'exact_identity';
    case Renamed = 'renamed';
    case BoundaryChanged = 'boundary_changed';
    case Split = 'split';
    case Merged = 'merged';
    case Partial = 'partial';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';

    public function mayPersistPopulation(): bool
    {
        return match ($this) {
            self::ExactIdentity, self::Renamed, self::BoundaryChanged, self::Split => true,
            default => false,
        };
    }
}
